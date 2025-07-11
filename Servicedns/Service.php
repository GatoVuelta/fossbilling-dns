<?php
/**
 * Copyright 2022-2023 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Servicedns;

use FOSSBilling\InjectionAwareInterface;
use RedBeanPHP\OODBBean;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    private $dnsProvider;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }
    
    private function chooseDnsProvider($config) {
        $providerName = $config['provider'];

        switch ($providerName) {
            case 'Bind':
                $this->dnsProvider = new Providers\Bind($config);
                break;
            case 'Desec':
                $this->dnsProvider = new Providers\Desec($config);
                break;
            case 'DNSimple':
                $this->dnsProvider = new Providers\DNSimple($config);
                break;
            case 'Hetzner':
                $this->dnsProvider = new Providers\Hetzner($config);
                break;
            case 'PowerDNS':
                $this->dnsProvider = new Providers\PowerDNS($config, $this->di);
                break;
            case 'Vultr':
                $this->dnsProvider = new Providers\Vultr($config);
                break;
            default:
                throw new \FOSSBilling\InformationException("Unknown DNS provider: {$providerName}");
        }
    }

    public function attachOrderConfig(\Model_Product $product, array $data): array
    {
        !empty($product->config) ? $config = json_decode($product->config, true) : $config = [];

        return array_merge($config, $data);
    }

    public function create(OODBBean $order)
    {
        $model = $this->di['db']->dispense('service_dns');
        $model->client_id = $order->client_id;
        $model->config = $order->config;
        $domainName = isset($order->config) ? json_decode($order->config)->domain_name : null;
        $model->domain_name = $domainName;

        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return $model;
    }

    public function activate(OODBBean $order, OODBBean $model): bool
    {
        $config = json_decode($order->config, 1);
        if (!is_object($model)) {
            throw new \FOSSBilling\InformationException('Order does not exist.');
        }

        $domainName = isset($order->config) ? json_decode($order->config)->domain_name : null;
        $model->domain_name = $domainName;
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        
        $this->chooseDnsProvider($config);
        if ($this->dnsProvider === null) {
            throw new \FOSSBilling\InformationException("DNS provider is not set.");
        }

        $this->dnsProvider->createDomain($domainName);

        return true;
    }

    public function suspend(OODBBean $order, OODBBean $model): bool
    {
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    public function unsuspend(OODBBean $order, OODBBean $model): bool
    {
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    public function cancel(OODBBean $order, OODBBean $model): bool
    {
        return $this->suspend($order, $model);
    }

    public function uncancel(OODBBean $order, OODBBean $model): bool
    {
        return $this->unsuspend($order, $model);
    }

    public function delete(?OODBBean $order, ?OODBBean $model): void
    {
        if ($order === null) {
            throw new \FOSSBilling\InformationException("Order is not provided.");
        }

        $config = json_decode($order->config, true);
        if (!$config) {
            throw new \FOSSBilling\InformationException("Invalid or missing DNS provider configuration.");
        }

        $this->chooseDnsProvider($config);
        if ($this->dnsProvider === null) {
            throw new \FOSSBilling\InformationException("DNS provider is not set.");
        }

        $domainName = $config['domain_name'] ?? null;
        if (empty($domainName)) {
            throw new \FOSSBilling\InformationException("Domain name is not set.");
        }

        try {
            // Attempt to delete the domain
            $this->dnsProvider->deleteDomain($domainName);
        } catch (\Exception $e) {
            // Check if the exception is due to the domain not being found.
            if (strpos($e->getMessage(), 'Not Found') !== false) {
                // Log the not found error but proceed with deleting the order.
                error_log("Domain $domainName not found in PowerDNS, but proceeding with order deletion.");
            } else {
                // For other exceptions, rethrow them as they indicate actual issues.
                throw new \FOSSBilling\InformationException("Failed to delete domain $domainName: " . $e->getMessage());
            }
        }

        // Proceed with deleting the order from the database.
        if (is_object($model)) {
            $this->di['db']->trash($model);
        }
    }

    public function toApiArray(OODBBean $model): array
    {
        $domain_id = $this->di['db']->findOne('service_dns', 'domain_name = :domain_name', [':domain_name' => $model->domain_name]);
        $records = $this->di['db']->getAll('SELECT id, type, host, value, ttl, priority FROM service_dns_records WHERE domain_id=:domain_id', ['domain_id' => $domain_id['id']]);

        return [
            'id' => $model->id,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
            'domain_name' => $model->domain_name,
            'records' => $records,
            'config' => json_decode($model->config, true),
        ];
    }

    /**
     * Used to add a DNS record for a specified domain.
     *
     * @param array $data An array containing the necessary information for adding a DNS record.
     * 
     * @return bool Returns true on successful addition of the DNS record, false otherwise.
     */
    public function addRecord(array $data): bool
    {           
        if (!empty($data['order_id'])) {
            $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
            $orderService = $this->di['mod_service']('order');
            $model = $orderService->getOrderService($order);
        }
        if (is_null($model)) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }

        try {
            $this->di['is_client_logged'];
            $client = $this->di['loggedin_client'];
        } catch (\Exception) {
            $client = null;
        }

        if (!is_null($client) && $client->id !== $model->client_id) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }

        $config = json_decode($model->config, true);
        $rrsetData = [
            'subname' => $data['record_name'],
            'type' => $data['record_type'],
            'ttl' => (int) $data['record_ttl'],
            'records' => [$data['record_value']]
        ];
        
        // Check if the record type is MX
        if ($data['record_type'] === 'MX') {
            if ($config['provider'] === 'Desec') {
                $rrsetData['records'] = [$data['record_priority'] . ' ' . $data['record_value']];
            } else {
                $rrsetData['priority'] = $data['record_priority'];
            }
        }
        
        $this->chooseDnsProvider($config);

        // Check if DNS provider is set
        if ($this->dnsProvider === null) {
            throw new \FOSSBilling\InformationException("DNS provider is not set.");
        }
        
        $domainName = isset($order->config) ? json_decode($order->config)->domain_name : null;
        $domain_id = $this->di['db']->findOne('service_dns', 'domain_name = :domain_name', [':domain_name' => $domainName]);
        
        // Special handling for CNAME records - they must be unique per hostname
        if ($data['record_type'] === 'CNAME') {
            // Check if any other record type exists with the same hostname
            $conflictingRecords = $this->di['db']->findOne('service_dns_records', 
                'domain_id = :domain_id AND host = :host AND type != :type', 
                [
                    ':domain_id' => $domain_id['id'],
                    ':host' => $data['record_name'],
                    ':type' => 'CNAME'
                ]
            );
            
            if ($conflictingRecords) {
                throw new \FOSSBilling\InformationException("Cannot add CNAME record: The hostname '{$data['record_name']}' already has other record types assigned to it.");
            }
        } else {
            // Check if a CNAME record exists with the same hostname
            $existingCname = $this->di['db']->findOne('service_dns_records', 
                'domain_id = :domain_id AND type = :type AND host = :host', 
                [
                    ':domain_id' => $domain_id['id'],
                    ':type' => 'CNAME',
                    ':host' => $data['record_name']
                ]
            );
            
            if ($existingCname) {
                throw new \FOSSBilling\InformationException("Cannot add {$data['record_type']} record: The hostname '{$data['record_name']}' already has a CNAME record assigned to it.");
            }
        }
        
        // Check if a record with the same domain_id, type, host, and value already exists
        $existingRecord = $this->di['db']->findOne('service_dns_records', 
            'domain_id = :domain_id AND type = :type AND host = :host AND value = :value', 
            [
                ':domain_id' => $domain_id['id'],
                ':type' => $data['record_type'],
                ':host' => $data['record_name'],
                ':value' => $data['record_value']
            ]
        );

        if ($existingRecord) {
            // Return an error if an exact duplicate record is being added
            throw new \FOSSBilling\InformationException(
                sprintf(
                    'A DNS record with the same host, type, and value already exists: %s %s %s', 
                    $data['record_name'], 
                    $data['record_type'], 
                    $data['record_value']
                )
            );
        } else {
            // Call the DNS provider's API to create the record
            // Some providers like PowerDNS will handle duplicates gracefully
            $this->dnsProvider->createRRset($config['domain_name'], $rrsetData);

            $model->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($model);
            
            $currentTime = date('Y-m-d H:i:s');
            
            // Create a new record as no duplicate exists
            $records = $this->di['db']->dispense('service_dns_records');
            $records->domain_id = $domain_id['id'];
            $records->type = $data['record_type'];
            $records->host = $data['record_name'];
            $records->value = $data['record_value'];
            $records->ttl = (int) $data['record_ttl'];
            $records->priority = (isset($data['record_priority']) && $data['record_priority'] !== '') ? $data['record_priority'] : 0;
            $records->created_at = $currentTime;
            $records->updated_at = $currentTime;
            $this->di['db']->store($records);
        }

        return true;
    }
    
    /**
     * Used to update a DNS record for a specified domain.
     *
     * @param array $data An array containing the necessary information for updating a DNS record.
     * 
     * @return bool Returns true on successful update of the DNS record, false otherwise.
     */
    public function updateRecord(array $data): bool
    {      
        if (!empty($data['order_id'])) {
            $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
            $orderService = $this->di['mod_service']('order');
            $model = $orderService->getOrderService($order);
        }
        if (is_null($model)) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }

        try {
            $this->di['is_client_logged'];
            $client = $this->di['loggedin_client'];
        } catch (\Exception) {
            $client = null;
        }

        if (!is_null($client) && $client->id !== $model->client_id) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }

        $config = json_decode($model->config, true);
        $rrsetData = [
            'ttl' => (int) $data['record_ttl'],
            'records' => [$data['record_value']]
        ];
        
        // Check if the record type is MX
        if ($data['record_type'] === 'MX') {
            if ($config['provider'] === 'Desec') {
                $rrsetData['records'] = [$data['record_priority'] . ' ' . $data['record_value']];
            } else {
                $rrsetData['priority'] = $data['record_priority'];
            }
        }

        $this->chooseDnsProvider($config);

        // Check if DNS provider is set
        if ($this->dnsProvider === null) {
            throw new \FOSSBilling\InformationException("DNS provider is not set.");
        }

        $this->dnsProvider->modifyRRset($config['domain_name'], $data['record_name'], $data['record_type'], $rrsetData);

        $domainName = isset($order->config) ? json_decode($order->config)->domain_name : null;
        $domain_id = $this->di['db']->findOne('service_dns', 'domain_name = :domain_name', [':domain_name' => $domainName]);     

        $this->di['db']->exec( 'UPDATE service_dns_records SET ttl=?, value = ?, updated_at = ? WHERE id = ? AND domain_id = ?' , [$data['record_ttl'], $data['record_value'], date('Y-m-d H:i:s'), $data['record_id'], $domain_id['id']] );

        return true;
    }
    
    /**
     * Used to delete a DNS record for a specified domain.
     *
     * @param array $data An array containing the identification information of the DNS record to be deleted.
     *
     * @return bool Returns true if the DNS record was successfully deleted, false otherwise.
     */
    public function delRecord(array $data): bool
    {
        if (!empty($data['order_id'])) {
            $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
            $orderService = $this->di['mod_service']('order');
            $model = $orderService->getOrderService($order);
        }
        if (is_null($model)) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }

        try {
            $this->di['is_client_logged'];
            $client = $this->di['loggedin_client'];
        } catch (\Exception) {
            $client = null;
        }

        if (!is_null($client) && $client->id !== $model->client_id) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }

        $config = json_decode($model->config, true);
        $this->chooseDnsProvider($config);

        // Check if DNS provider is set
        if ($this->dnsProvider === null) {
            throw new \FOSSBilling\InformationException("DNS provider is not set.");
        }

        if ($data['record_type'] === 'MX') { 
            $data['record_value'] = $data['record_priority'] . ' ' . $data['record_value'];
        }

        $this->dnsProvider->deleteRRset($config['domain_name'], $data['record_name'], $data['record_type'], $data['record_value']);

        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        $domainName = isset($order->config) ? json_decode($order->config)->domain_name : null;
        $domain_id = $this->di['db']->findOne('service_dns', 'domain_name = :domain_name', [':domain_name' => $domainName]);     

        $this->di['db']->exec(
            'DELETE FROM service_dns_records WHERE id = ? AND domain_id = ?', 
            [$data['record_id'], $domain_id['id']]
        );

        return true;
    }

    /**
     * Creates the database structure to store the DNS records in.
     */
    public function install(): bool
    {
        $sql = '
        CREATE TABLE IF NOT EXISTS `service_dns` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT UNIQUE,
            `client_id` bigint(20) NOT NULL,
            `domain_name` varchar(75),
            `provider_id` varchar(11),
            `zoneId` varchar(100) DEFAULT NULL,
            `config` text NOT NULL,
            `created_at` datetime,
            `updated_at` datetime,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
        CREATE TABLE IF NOT EXISTS `service_dns_records` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `domain_id` bigint(20) NOT NULL,
            `recordId` varchar(100) DEFAULT NULL,
            `type` varchar(10) NOT NULL,
            `host` varchar(255) NOT NULL,
            `value` text NOT NULL,
            `ttl` int(11) DEFAULT NULL,
            `priority` int(11) DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            FOREIGN KEY (`domain_id`) REFERENCES `service_dns`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;';
        $this->di['db']->exec($sql);

        return true;
    }

    /**
     * Removes the DNS records from the database.
     */
    public function uninstall(): bool
    {
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_dns_records`');
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_dns`');

        return true;
    }

    private function isActive(OODBBean $model): bool
    {
        $order = $this->di['db']->findOne('ClientOrder', 'service_id = :id AND service_type = "dns"', [':id' => $model->id]);
        if (is_null($order)) {
            throw new \FOSSBilling\InformationException('DNS record does not exist');
        }

        return $order->status === 'active';
    }

    /*public static function onBeforeAdminCronRun(\Box_Event $event): void
    {
        $di = $event->getDi();
        
        // Check if the module 'servicedns' is activated
        $sqlCheck = 'SELECT COUNT(*) FROM extension WHERE name = :name AND status = :status';
        $stmtCheck = $di['pdo']->prepare($sqlCheck);
        $stmtCheck->bindValue(':name', 'servicedns');
        $stmtCheck->bindValue(':status', 'installed');
        $stmtCheck->execute();
        $count = $stmtCheck->fetchColumn();

        if ($count > 0) {
            $sqlFetchProduct = 'SELECT config FROM product WHERE title = :title LIMIT 1';
            $stmtFetchProduct = $di['pdo']->prepare($sqlFetchProduct);
            $stmtFetchProduct->bindValue(':title', 'DNS hosting');
            $stmtFetchProduct->execute();
            $productRow = $stmtFetchProduct->fetch(\PDO::FETCH_ASSOC);

            if ($productRow) {
                $configuJson = $productRow['config'];
                $configArray = json_decode($configuJson, true);

                $service = $di['mod_service']('servicedns');
                $service->chooseDnsProvider($configArray);

                if ($service->dnsProvider === null) {
                    throw new \FOSSBilling\InformationException("DNS provider is not set.");
                }

                $sqlFetchDomains = 'SELECT * FROM service_dns';
                $stmtFetchDomains = $di['pdo']->prepare($sqlFetchDomains);
                $stmtFetchDomains->execute();
                $rows = $stmtFetchDomains->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $domainName = $row['domain_name'];

                    try {
                        // Assuming getDomain is the method you want to call
                        //$result = $service->dnsProvider->getDomain($domainName);
                        // Do something with $result if needed
                    } catch (\Exception $e) {
                        // Handle any exceptions during domain processing
                        echo "Failed to process domain '{$domainName}': " . $e->getMessage() . "\n";
                    }
                }
            } else {
                throw new \FOSSBilling\InformationException("Product with title 'DNS hosting' not found.");
            }
        }
    }*/
}
