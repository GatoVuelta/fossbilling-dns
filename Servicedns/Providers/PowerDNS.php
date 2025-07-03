<?php

namespace Box\Mod\Servicedns\Providers;

use Exonet\Powerdns\Powerdns as PowerdnsApi;
use Exonet\Powerdns\RecordType;
use Exonet\Powerdns\Resources\ResourceRecord;
use Exonet\Powerdns\Resources\Record;

class PowerDNS implements DnsHostingProviderInterface {
    private $client;
    private $nsRecords;
    private $di;

    public function __construct($config, ?\Pimple\Container $di = null) {
        $this->di = $di;
        $token = $config['apikey'];
        $api_ip = $config['powerdnsapi'];
        if (empty($token)) {
            throw new \FOSSBilling\InformationException("API token cannot be empty");
        }
        if (empty($api_ip)) {
            $api_ip = '127.0.0.1';
        }
        
        // Dynamically pull nameserver settings from the configuration
        $this->nsRecords = [
            'ns1' => $config['ns1'] ?? null,
            'ns2' => $config['ns2'] ?? null,
            'ns3' => $config['ns3'] ?? null,
            'ns4' => $config['ns4'] ?? null,
            'ns5' => $config['ns5'] ?? null,
        ];

        $this->client = new PowerdnsApi($api_ip, $token);
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new \FOSSBilling\InformationException("Domain name cannot be empty");
        }

        $nsRecords = array_filter($this->nsRecords);
        $formattedNsRecords = array_values(array_map(function($nsRecord) {
            return rtrim($nsRecord, '.') . '.';
        }, $nsRecords));

        try {
            $this->client->createZone($domainName, $formattedNsRecords);
            // On successful creation, simply return true.
            return true;
        } catch (\Exception $e) {
            // Throw an exception to indicate failure, including for conflicts.
            if (strpos($e->getMessage(), 'Conflict') !== false) {
                throw new \FOSSBilling\InformationException("Zone already exists for domain: " . $domainName);
            } else {
                throw new \FOSSBilling\InformationException("Failed to create zone for domain: " . $domainName . ". Error: " . $e->getMessage());
            }
        }
    }

    public function listDomains() {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function getDomain($domainName) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function getResponsibleDomain($qname) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function exportDomainAsZonefile($domainName) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function deleteDomain($domainName) {
        if (empty($domainName)) {
            throw new \FOSSBilling\InformationException("Domain name cannot be empty");
        }
        
        $this->client->deleteZone($domainName);

        return json_decode($domainName, true);
    }
    
    public function createRRset($domainName, $rrsetData) {
        $zone = $this->client->zone($domainName);
        
        if (!isset($rrsetData['subname'], $rrsetData['type'], $rrsetData['ttl'], $rrsetData['records'])) {
            throw new \FOSSBilling\InformationException("Missing data for creating RRset");
        }
        
        $subname = $rrsetData['subname'];
        $type = $rrsetData['type'];
        $ttl = $rrsetData['ttl'];
        $newRecordValue = $rrsetData['records'][0];

        // Convert record type string to RecordType enum
        switch ($type) {
            case 'A':
                $recordType = RecordType::A;
                break;
            case 'AAAA':
                $recordType = RecordType::AAAA;
                break;
            case 'CNAME':
                $recordType = RecordType::CNAME;
                break;
            case 'MX':
                $recordType = RecordType::MX;
                break;
            case 'TXT':
                $recordType = RecordType::TXT;
                break;
            case 'SPF':
                $recordType = RecordType::SPF;
                break;
            case 'DS':
                $recordType = RecordType::DS;
                break;
            default:
                throw new \FOSSBilling\InformationException("Invalid record type");
        }
        
        try {
            // Get the service_dns model that includes the domain ID
            $db = $this->di['db'];
            $domain = $db->findOne(
                'service_dns',
                'domain_name = :domain_name',
                [':domain_name' => $domainName]
            );
            
            if (!$domain) {
                throw new \FOSSBilling\InformationException("Domain not found in database: {$domainName}");
            }
            
            $domainId = $domain['id'];
            
            // Retrieve all existing records with the same host and type from the database
            $existingRecords = $this->di['db']->getAll(
                'SELECT value FROM service_dns_records WHERE domain_id = :domain_id AND type = :type AND host = :host',
                [
                    ':domain_id' => $domainId,
                    ':type' => $type,
                    ':host' => $subname
                ]
            );
            
            // Collect all existing record values
            $recordValues = [];
            foreach ($existingRecords as $record) {
                $recordValues[] = $record['value'];
            }
            
            // Add the new record if it doesn't already exist
            if (!in_array($newRecordValue, $recordValues)) {
                $recordValues[] = $newRecordValue;
            }
            
            // Prepare records in the format expected by PowerDNS
            $recordsArray = [];
            foreach ($recordValues as $recordContent) {
                // Create a proper Record object instead of an array
                $record = new \Exonet\Powerdns\Resources\Record();
                $record
                    ->setContent($recordContent)
                    ->setDisabled(false);
                $recordsArray[] = $record;
            }
            
            // Format the subname to be canonical (ending with dot)
            if ($subname === '' || $subname === '@') {
                // For the root domain
                $canonicalName = $domainName . '.';
            } else {
                // For subdomains - ensure it ends with domain and dot
                if (strpos($subname, $domainName) === false) {
                    $canonicalName = $subname . '.' . $domainName . '.';
                } else {
                    // If subname already includes domain, just ensure it ends with dot
                    $canonicalName = rtrim($subname, '.') . '.';
                }
            }
            
            // Use the patch method to update all records at once with REPLACE changetype
            $resourceRecord = new \Exonet\Powerdns\Resources\ResourceRecord();
            $resourceRecord->setName($canonicalName)
                ->setType($recordType)
                ->setTtl($ttl)
                ->setRecords($recordsArray)
                ->setChangetype('REPLACE');
                
            $zone->patch([$resourceRecord]);
            
            return json_decode($domainName, true);
            
        } catch (\Exception $e) {
            throw new \FOSSBilling\InformationException("Error creating record: " . $e->getMessage());
        }
    }

    public function createBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function retrieveAllRRsets($domainName) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function retrieveSpecificRRset($domainName, $subname, $type) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function modifyRRset($domainName, $subname, $type, $rrsetData) {
        $zone = $this->client->zone($domainName);

        if (!isset($subname, $type, $rrsetData['ttl'], $rrsetData['records'])) {
            throw new \FOSSBilling\InformationException("Missing data for creating RRset");
        }
        
        $ttl = $rrsetData['ttl'];
        $recordValue = $rrsetData['records'][0];

        switch ($type) {
            case 'A':
                $recordType = RecordType::A;
                break;
            case 'AAAA':
                $recordType = RecordType::AAAA;
                break;
            case 'CNAME':
                $recordType = RecordType::CNAME;
                break;
            case 'MX':
                $recordType = RecordType::MX;
                break;
            case 'TXT':
                $recordType = RecordType::TXT;
                break;
            case 'SPF':
                $recordType = RecordType::SPF;
                break;
            case 'DS':
                $recordType = RecordType::DS;
                break;
            default:
                throw new \FOSSBilling\InformationException("Invalid record type");
        }

        $zone->create($subname, $recordType, $recordValue, $ttl);
        
        return json_decode($domainName, true);
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value) {
        $zone = $this->client->zone($domainName);
        
        if (!isset($subname, $type, $value)) {
            throw new \FOSSBilling\InformationException("Missing data for creating RRset");
        }
        
        switch ($type) {
            case 'A':
                $recordType = RecordType::A;
                break;
            case 'AAAA':
                $recordType = RecordType::AAAA;
                break;
            case 'CNAME':
                $recordType = RecordType::CNAME;
                break;
            case 'MX':
                $recordType = RecordType::MX;
                break;
            case 'TXT':
                $recordType = RecordType::TXT;
                break;
            case 'SPF':
                $recordType = RecordType::SPF;
                break;
            case 'DS':
                $recordType = RecordType::DS;
                break;
            default:
                throw new \FOSSBilling\InformationException("Invalid record type");
        }

        $zone->find($subname, $recordType)->delete();
        
        return json_decode($domainName, true);
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

}
