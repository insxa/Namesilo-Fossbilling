<?php

declare(strict_types=1);

/**
 *Copyright 2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * NameSilo registrar adapter for FOSSBilling.
 *
 * Documentation: https://github.com/insxa/Namesilo-Fossbilling
 *
 * NameSilo API:
 * https://www.namesilo.com/api-reference
 *
 * All NameSilo API calls are GET requests.
 */

class Registrar_Adapter_Namesilo extends Registrar_AdapterAbstract
{
    public $config = [
        'api-key' => null,
        'payment-id' => null,
        'default-private' => true,
        'default-auto-renew' => true,
    ];

    /**
     * Constructor.
     */
    public function __construct($options)
    {
        if (isset($options['api-key']) && trim((string) $options['api-key']) !== '') {
            $this->config['api-key'] = trim((string) $options['api-key']);
        } else {
            throw new Registrar_Exception(
                'The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing',
                [
                    ':domain_registrar' => 'NameSilo',
                    ':missing' => 'NameSilo API Key',
                ],
                3001
            );
        }

        $this->config['payment-id'] = isset($options['payment-id'])
            ? trim((string) $options['payment-id'])
            : '';

        $this->config['default-private'] = $this->toBool(
            $options['default-private'] ?? '1'
        );

        $this->config['default-auto-renew'] = $this->toBool(
            $options['default-auto-renew'] ?? '1'
        );
    }

    /**
     * FOSSBilling registrar configuration.
     *
     * multiOptions is used for the Yes/No radio fields.
     */
    public static function getConfig(): array
    {
        return [
            'label' => 'Manages domains on NameSilo via API.',
            'form' => [
                'api-key' => [
                    'password',
                    [
                        'label' => 'NameSilo API Key',
                        'description' => 'Your NameSilo API key.',
                        'required' => true,
                        'renderPassword' => true,
                    ],
                ],

                'payment-id' => [
                    'text',
                    [
                        'label' => 'NameSilo Payment ID',
                        'description' => 'Optional verified NameSilo payment ID. Leave empty to use account funds.',
                        'required' => false,
                    ],
                ],

                'default-private' => [
                    'radio',
                    [
                        'label' => 'Enable WHOIS privacy by default',
                        'description' => 'Enable NameSilo WHOIS privacy when registering new domains.',
                        'multiOptions' => [
                            '1' => 'Yes',
                            '0' => 'No',
                        ],
                    ],
                ],

                'default-auto-renew' => [
                    'radio',
                    [
                        'label' => 'Enable automatic renewal by default',
                        'description' => 'Enable NameSilo automatic renewal when registering new domains.',
                        'multiOptions' => [
                            '1' => 'Yes',
                            '0' => 'No',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Check domain registration availability.
     */
    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $domainName = trim((string) $domain->getName());

        if ($domainName === '') {
            throw new Registrar_Exception(
                'Domain name cannot be empty.'
            );
        }

        $xml = $this->_request(
            'checkRegisterAvailability',
            [
                'domains' => $domainName,
            ]
        );

        /*
         * NameSilo returns:
         *
         * <available>
         *     <domain price="...">example.com</domain>
         * </available>
         *
         * It may also return multiple <domain> nodes.
         */
        if (!isset($xml->reply->available->domain)) {
            return false;
        }

        foreach ($xml->reply->available->domain as $availableDomain) {
            if (
                strcasecmp(
                    trim((string) $availableDomain),
                    $domainName
                ) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a domain can be transferred to NameSilo.
     */
    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $domainName = trim((string) $domain->getName());

        if ($domainName === '') {
            throw new Registrar_Exception(
                'Domain name cannot be empty.'
            );
        }

        $xml = $this->_request(
            'checkTransferAvailability',
            [
                'domains' => $domainName,
            ]
        );

        if (!isset($xml->reply->available->domain)) {
            return false;
        }

        foreach ($xml->reply->available->domain as $availableDomain) {
            if (
                strcasecmp(
                    trim((string) $availableDomain),
                    $domainName
                ) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Change domain nameservers.
     */
    public function modifyNs(Registrar_Domain $domain): bool
    {
        $params = [
            'domain' => $domain->getName(),
        ];

        $this->addNameservers($params, $domain);

        if (!isset($params['ns1'], $params['ns2'])) {
            throw new Registrar_Exception(
                'At least two nameservers are required.'
            );
        }

        $this->_request('changeNameServers', $params);

        return true;
    }

    /**
     * Modify registrant contact information.
     */
    public function modifyContact(Registrar_Domain $domain): bool
    {
        $domainInfo = $this->_request(
            'getDomainInfo',
            [
                'domain' => $domain->getName(),
            ]
        );

        $contactId = trim(
            (string) (
                $domainInfo->reply->contact_ids->registrant ?? ''
            )
        );

        if ($contactId === '') {
            throw new Registrar_Exception(
                'NameSilo did not return a registrant contact ID for :domain.',
                [
                    ':domain' => $domain->getName(),
                ]
            );
        }

        $contact = $domain->getContactRegistrar();

        $params = [
            'contact_id' => $contactId,
            'fn' => $contact->getFirstName(),
            'ln' => $contact->getLastName(),
            'ad' => $contact->getAddress1(),
            'cy' => $contact->getCity(),
            'st' => $contact->getState(),
            'zp' => $contact->getZip(),
            'ct' => $contact->getCountry(),
            'em' => $contact->getEmail(),
            'ph' => $this->formatPhone($contact->getTel()),
        ];

        if ($contact->getAddress2()) {
            $params['ad2'] = $contact->getAddress2();
        }

        if ($contact->getCompany()) {
            $params['cp'] = $contact->getCompany();
        }

        if ($contact->getFax()) {
            $params['fx'] = $this->formatPhone($contact->getFax());
        }

        $this->_request('contactUpdate', $params);

        return true;
    }

    /**
     * Transfer a domain into NameSilo.
     */
    public function transferDomain(Registrar_Domain $domain): bool
    {
        $contact = $domain->getContactRegistrar();

        $params = [
            'domain' => $domain->getName(),
            'auth' => $domain->getEpp(),
            'private' => $this->config['default-private'] ? '1' : '0',
            'auto_renew' => $this->config['default-auto-renew'] ? '1' : '0',

            'fn' => $contact->getFirstName(),
            'ln' => $contact->getLastName(),
            'ad' => $contact->getAddress1(),
            'cy' => $contact->getCity(),
            'st' => $contact->getState(),
            'zp' => $contact->getZip(),
            'ct' => $contact->getCountry(),
            'em' => $contact->getEmail(),
            'ph' => $this->formatPhone($contact->getTel()),
        ];

        if ($contact->getAddress2()) {
            $params['ad2'] = $contact->getAddress2();
        }

        if ($contact->getCompany()) {
            $params['cp'] = $contact->getCompany();
        }

        if ($contact->getFax()) {
            $params['fx'] = $this->formatPhone($contact->getFax());
        }

        $this->addNameservers($params, $domain);

        if (!empty($this->config['payment-id'])) {
            $params['payment_id'] = $this->config['payment-id'];
        }

        /*
         * .US requires these fields.
         *
         * FOSSBilling's domain model does not currently expose
         * dedicated NameSilo .US nexus/application-purpose fields,
         * so these defaults mirror the legacy NameSilo adapter.
         */
        if ($this->getTld($domain) === '.us') {
            $params['usnc'] = 'C12';
            $params['usap'] = 'P3';
        }

        $this->_request('transferDomain', $params);

        return true;
    }

    /**
     * Retrieve registered domain details.
     */
    public function getDomainDetails(Registrar_Domain $domain)
    {
        $xml = $this->_request(
            'getDomainInfo',
            [
                'domain' => $domain->getName(),
            ]
        );

        $reply = $xml->reply;

        /*
         * Registration date.
         */
        if (isset($reply->created)) {
            $created = strtotime((string) $reply->created);

            if ($created !== false) {
                $domain->setRegistrationTime($created);
            }
        }

        /*
         * Expiration date.
         */
        if (isset($reply->expires)) {
            $expires = strtotime((string) $reply->expires);

            if ($expires !== false) {
                $domain->setExpirationTime($expires);
            }
        }

        /*
         * WHOIS privacy.
         */
        $domain->setPrivacyEnabled(
            strcasecmp(
                trim((string) ($reply->private ?? 'No')),
                'Yes'
            ) === 0
        );

        /*
         * Nameservers.
         */
        if (isset($reply->nameservers->nameserver)) {
            $position = 1;

            foreach ($reply->nameservers->nameserver as $nameserver) {
                $nameserver = trim((string) $nameserver);

                if ($nameserver === '') {
                    continue;
                }

                switch ($position) {
                    case 1:
                        $domain->setNs1($nameserver);
                        break;

                    case 2:
                        $domain->setNs2($nameserver);
                        break;

                    case 3:
                        $domain->setNs3($nameserver);
                        break;

                    case 4:
                        $domain->setNs4($nameserver);
                        break;
                }

                $position++;

                if ($position > 4) {
                    break;
                }
            }
        }

        /*
         * Retrieve registrant contact.
         */
        $contactId = trim(
            (string) (
                $reply->contact_ids->registrant ?? ''
            )
        );

        if ($contactId !== '') {
            $contactXml = $this->_request(
                'contactList',
                [
                    'contact_id' => $contactId,
                ]
            );

            if (isset($contactXml->reply->contact)) {
                $contactData = $contactXml->reply->contact;

                $contact = new Registrar_Domain_Contact();

                $contact
                    ->setFirstName((string) ($contactData->first_name ?? ''))
                    ->setLastName((string) ($contactData->last_name ?? ''))
                    ->setEmail((string) ($contactData->email ?? ''))
                    ->setCompany((string) ($contactData->company ?? ''))
                    ->setTel((string) ($contactData->phone ?? ''))
                    ->setAddress1((string) ($contactData->address ?? ''))
                    ->setAddress2((string) ($contactData->address2 ?? ''))
                    ->setCity((string) ($contactData->city ?? ''))
                    ->setCountry((string) ($contactData->country ?? ''))
                    ->setZip((string) ($contactData->zip ?? ''));

                if (isset($contactData->state)) {
                    $contact->setState(
                        (string) $contactData->state
                    );
                }

                if (isset($contactData->fax)) {
                    $contact->setFax(
                        (string) $contactData->fax
                    );
                }

                $contact->setId($contactId);

                $domain->setContactRegistrar($contact);
            }
        }

        return $domain;
    }

    /**
     * NameSilo does not expose a delete-domain operation
     * suitable for FOSSBilling's registrar deletion action.
     */
    public function deleteDomain(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(
            'NameSilo does not support deleting a registered domain through the API.'
        );
    }

    /**
     * Register a new domain.
     */
    public function registerDomain(Registrar_Domain $domain): bool
    {
        $contact = $domain->getContactRegistrar();

        $years = (int) $domain->getRegistrationPeriod();

        if ($years < 1) {
            $years = 1;
        }

        if ($years > 10) {
            $years = 10;
        }

        $params = [
            'domain' => $domain->getName(),
            'years' => $years,

            /*
             * NameSilo explicitly supports these two parameters.
             *
             * private=1  -> WHOIS privacy
             * auto_renew=1 -> automatic renewal
             */
            'private' => $this->config['default-private'] ? '1' : '0',
            'auto_renew' => $this->config['default-auto-renew'] ? '1' : '0',

            'fn' => $contact->getFirstName(),
            'ln' => $contact->getLastName(),
            'ad' => $contact->getAddress1(),
            'cy' => $contact->getCity(),
            'st' => $contact->getState(),
            'zp' => $contact->getZip(),
            'ct' => $contact->getCountry(),
            'em' => $contact->getEmail(),
            'ph' => $this->formatPhone($contact->getTel()),
        ];

        /*
         * Optional contact fields.
         */
        if ($contact->getAddress2()) {
            $params['ad2'] = $contact->getAddress2();
        }

        if ($contact->getCompany()) {
            $params['cp'] = $contact->getCompany();
        }

        if ($contact->getFax()) {
            $params['fx'] = $this->formatPhone($contact->getFax());
        }

        /*
         * Namesilo allows up to 13 nameservers.
         *
         * FOSSBilling's Registrar_Domain currently exposes the
         * first four through the standard adapter interface.
         */
        $this->addNameservers($params, $domain);

        /*
         * Optional payment method.
         */
        if (!empty($this->config['payment-id'])) {
            $params['payment_id'] = $this->config['payment-id'];
        }

        /*
         * .US registration requirements.
         *
         * These are the same defaults used by the older NameSilo
         * FOSSBilling adapter.
         */
        if ($this->getTld($domain) === '.us') {
            $params['usnc'] = 'C12';
            $params['usap'] = 'P3';
        }

        $this->_request('registerDomain', $params);

        return true;
    }

    /**
     * Renew domain.
     */
    public function renewDomain(Registrar_Domain $domain): bool
    {
        $years = (int) $domain->getRegistrationPeriod();

        if ($years < 1) {
            $years = 1;
        }

        $params = [
            'domain' => $domain->getName(),
            'years' => $years,
        ];

        if (!empty($this->config['payment-id'])) {
            $params['payment_id'] = $this->config['payment-id'];
        }

        $this->_request('renewDomain', $params);

        return true;
    }

    /**
     * Enable WHOIS privacy.
     */
    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        if ($this->privacyUnsupported($domain)) {
            throw new Registrar_Exception(
                'NameSilo WHOIS privacy is not available for :domain.',
                [
                    ':domain' => $domain->getName(),
                ]
            );
        }

        $this->_request(
            'addPrivacy',
            [
                'domain' => $domain->getName(),
            ]
        );

        return true;
    }

    /**
     * Disable WHOIS privacy.
     */
    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->_request(
            'removePrivacy',
            [
                'domain' => $domain->getName(),
            ]
        );

        return true;
    }

    /**
     * Request EPP/auth code.
     *
     * IMPORTANT:
     * NameSilo's retrieveAuthCode operation sends the actual
     * authorization code to the administrative contact email.
     * The API response does not contain the actual EPP code.
     */
    public function getEpp(Registrar_Domain $domain): string
    {
        $this->_request(
            'retrieveAuthCode',
            [
                'domain' => $domain->getName(),
            ]
        );

        /*
         * Registrar_AdapterAbstract requires a string.
         * NameSilo does not return the actual code through this
         * operation, so returning the email notification text
         * is more accurate than pretending it is the EPP code.
         */
        return 'NameSilo has emailed the EPP transfer code to the administrative contact.';
    }

    /**
     * Lock domain.
     */
    public function lock(Registrar_Domain $domain): bool
    {
        $this->_request(
            'domainLock',
            [
                'domain' => $domain->getName(),
            ]
        );

        return true;
    }

    /**
     * Unlock domain.
     */
    public function unlock(Registrar_Domain $domain): bool
    {
        $this->_request(
            'domainUnlock',
            [
                'domain' => $domain->getName(),
            ]
        );

        return true;
    }

    /**
     * Test environment helper.
     */
    public function isTestEnv(): bool
    {
        return $this->_testMode;
    }

    /**
     * Execute a NameSilo API request and parse the XML response.
     *
     * NameSilo requires GET requests.
     */
    private function _request(string $operation, array $params = []): \SimpleXMLElement
    {
        $query = [
            'version' => 1,
            'type' => 'xml',
            'key' => $this->config['api-key'],
        ];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            $query[$key] = $value;
        }

        $url = $this->getApiUrl() . $operation;

        /*
         * Do not put the API key into logs.
         */
        $logQuery = $query;
        $logQuery['key'] = 'REDACTED';

        $this->getLog()->debug(
            'NameSilo API request: ' .
            $url .
            '?' .
            http_build_query($logQuery)
        );

        try {
            $response = $this->getHttpClient()->request(
                'GET',
                $url,
                [
                    'query' => $query,
                    'timeout' => 60,
                ]
            );

            $body = $response->getContent(false);
        } catch (\Throwable $e) {
            $this->getLog()->error(
                'NameSilo API connection error: ' .
                $e->getMessage()
            );

            throw new Registrar_Exception(
                'Unable to connect to the NameSilo API. Check the FOSSBilling log for details.'
            );
        }

        if (trim($body) === '') {
            throw new Registrar_Exception(
                'NameSilo returned an empty API response.'
            );
        }

        /*
         * Log the response, but make sure the API key can never
         * appear here.
         */
        $this->getLog()->debug(
            'NameSilo API response: ' . $body
        );

        libxml_use_internal_errors(true);

        try {
            $xml = new \SimpleXMLElement(
                $body,
                LIBXML_NONET | LIBXML_NOCDATA
            );
        } catch (\Throwable $e) {
            $errors = libxml_get_errors();
            libxml_clear_errors();

            $errorMessage = 'Unable to parse NameSilo XML response.';

            if (!empty($errors)) {
                $errorMessage .= ' ' . trim(
                    (string) $errors[0]->message
                );
            }

            throw new Registrar_Exception($errorMessage);
        }

        libxml_clear_errors();

        if (!isset($xml->reply)) {
            throw new Registrar_Exception(
                'NameSilo returned an invalid API response: missing reply element.'
            );
        }

        $code = isset($xml->reply->code)
            ? (int) $xml->reply->code
            : 0;

        if ($code !== 300) {
            $detail = trim(
                (string) (
                    $xml->reply->detail
                    ?? $xml->reply->message
                    ?? 'Unknown NameSilo API error.'
                )
            );

            if ($detail === '') {
                $detail = 'Unknown NameSilo API error.';
            }

            throw new Registrar_Exception(
                'NameSilo API error (:code): :detail',
                [
                    ':code' => (string) $code,
                    ':detail' => $detail,
                ]
            );
        }

        return $xml;
    }

    /**
     * Get NameSilo API endpoint.
     */
    private function getApiUrl(): string
    {
        if ($this->_testMode) {
            return 'https://sandbox.namesilo.com/api/';
        }

        return 'https://www.namesilo.com/api/';
    }

    /**
     * Add FOSSBilling nameservers to a NameSilo request.
     */
    private function addNameservers(
        array &$params,
        Registrar_Domain $domain
    ): void {
        $nameservers = [
            1 => $domain->getNs1(),
            2 => $domain->getNs2(),
            3 => $domain->getNs3(),
            4 => $domain->getNs4(),
        ];

        foreach ($nameservers as $number => $nameserver) {
            if (
                $nameserver !== null &&
                trim((string) $nameserver) !== ''
            ) {
                $params['ns' . $number] = trim(
                    (string) $nameserver
                );
            }
        }
    }

    /**
     * Return the domain TLD in the format ".com".
     */
    private function getTld(Registrar_Domain $domain): string
    {
        $tld = strtolower(trim((string) $domain->getTld()));

        if ($tld === '') {
            return '';
        }

        if ($tld[0] !== '.') {
            $tld = '.' . $tld;
        }

        return $tld;
    }

    /**
     * Some TLDs do not support NameSilo WHOIS privacy.
     */
    private function privacyUnsupported(Registrar_Domain $domain): bool
    {
        return in_array(
            $this->getTld($domain),
            [
                '.us',
                '.ca',
            ],
            true
        );
    }

    /**
     * Format phone number for NameSilo.
     *
     * NameSilo's API expects the phone number without the
     * country dialing prefix for US/CA and generally expects
     * the contact's local phone representation.
     *
     * We preserve the digits while removing separators.
     */
    private function formatPhone(?string $phone): string
    {
        if ($phone === null) {
            return '';
        }

        return preg_replace(
            '/[^\d+]/',
            '',
            trim($phone)
        ) ?? '';
    }

    /**
     * Convert saved FOSSBilling configuration values to bool.
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return in_array(
            strtolower(trim((string) $value)),
            [
                '1',
                'true',
                'yes',
                'on',
            ],
            true
        );
    }
}
