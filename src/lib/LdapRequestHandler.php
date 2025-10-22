<?php

require_once __DIR__ . '/user_ispconfig.php';
require_once __DIR__ . '/util.php';

use FreeDSx\Ldap\Entry\Entry;
use FreeDSx\Ldap\Entry\Entries;
use FreeDSx\Ldap\Exception\OperationException;
use FreeDSx\Ldap\Server\RequestHandler\GenericRequestHandler;
use FreeDSx\Ldap\Server\RequestContext;
use FreeDSx\Ldap\Operation\Request\SearchRequest;
use FreeDSx\Ldap\Search\Filter\AndFilter;
use FreeDSx\Ldap\Search\Filter\EqualityFilter;
use ISPLDAP\lib\Util;

class LdapRequestHandler extends GenericRequestHandler
{


    /**
     * Validates the username/password of a simple bind request
     *
     * @param string $username
     * @param string $password
     * @return bool
     */
    public function bind(string $username, string $password): bool
    {
        global $config;
        $debug = $config['debug_mode'] ?? false;

        if ($debug) {
            echo "\n=== LDAP BIND ATTEMPT ===\n";
            echo "Username received: " . $username . "\n";
        }

        // Check if username is in DN format (CN=...,DC=...) and convert to email
        if (strpos($username, 'CN=') !== false || strpos($username, 'cn=') !== false) {
            if ($debug) echo "Detected DN format, converting to email...\n";
            $username = Util::getMailFromDN($username);
            if ($debug) echo "Converted to: " . $username . "\n";
        }

        if ($debug) {
            echo "Password length: " . strlen($password) . "\n";
            echo "Domain extracted: " . Util::getDomainFromMail($username) . "\n";
            echo "Allowed domains: " . json_encode($config['accept_domain_only'] ?? []) . "\n";
        }

        #Verify if domain is in allowed list
        if ((isset($config['accept_domain_only'])) && (count($config['accept_domain_only']) > 0)) {
            $userDomain = strtolower(Util::getDomainFromMail($username));
            $allowedDomains = array_map('strtolower', $config['accept_domain_only']);

            if ($debug) {
                echo "Checking domain: '$userDomain' against: " . json_encode($allowedDomains) . "\n";
            }

            if (!in_array($userDomain, $allowedDomains)) {
                if ($debug) echo "Domain NOT in allowed list - REJECTED\n";
                return false;
            }
            if ($debug) echo "Domain IS in allowed list - OK\n";
        } else {
            if ($debug) echo "No domain restrictions configured\n";
        }

        if ($debug) {
            echo "SOAP URL: " . $config['soap_url'] . "\n";
            echo "SOAP Location: " . $config['soap_location'] . "\n";
            echo "SOAP Validate Cert: " . ($config['soap_validate_cert'] ? 'true' : 'false') . "\n";
        }

        try {
            if ($debug) echo "Creating ISPConfig SOAP connection...\n";

            $a = new \OC_User_ISPCONFIG(
                $config['soap_location'],
                $config['soap_url'],
                $config['remote_soap_user'],
                $config['remote_soap_pass'],
                ['map_uids' => false, 'validateCert' => $config['soap_validate_cert']]
            );

            if ($debug) echo "Checking password via ISPConfig SOAP...\n";
            $b = $a->checkPassword($username, $password);

            if ($b) {
                if ($debug) echo "Authentication SUCCESS for user: " . $username . "\n";
                return true;
            } else {
                if ($debug) echo "Authentication FAILED for user: " . $username . "\n";
                return false;
            }
        } catch (\Exception $e) {
            if ($debug) {
                echo "Exception during bind: " . $e->getMessage() . "\n";
                echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
            }
            return false;
        }
    }

    /**
     * Override the search request. This must send back an entries object.
     *
     * @param RequestContext $context
     * @param SearchRequest $search
     * @return Entries
     */

    public function search(RequestContext $context, SearchRequest $search): Entries
    {
        //echo "search\n";
        global $config;
        #   var_dump($search);

        /*
        echo "User:  " . $context->token()->getUsername() . "\n";
        echo "Pass:  " . $context->token()->getPassword() . "\n";
        echo "Search:" . $search->getBaseDn() . "\n";
        echo "Filter:" . $search->getFilter() . "\n";
        */
        $filter = $search->getFilter();
        #var_dump($filter);
        if (($filter instanceof AndFilter) || ($filter instanceof EqualityFilter)) {
            if ($filter instanceof EqualityFilter)
                $filter2 = $filter;
            else
                $filter2 = $filter->get()[1];

            #echo "Filter attr:" . $filter2->getAttribute() . "\n"; #campo
            //echo "Filter rule:" . $filter2->getValue() . "\n"; #valor
            $email = $filter2->getValue();

            #Verify if domain is in allowed list
            if ((isset($config['accept_domain_only'])) && (count($config['accept_domain_only']) > 0)) {
                if (!in_array(strtolower(Util::getDomainFromMail($email)),  array_map('strtolower', $config['accept_domain_only'])))
                    throw new OperationException("This user's domain is not allowed to be searched.");
            }

            $a = new \OC_User_ISPCONFIG(
                $config['soap_location'],
                $config['soap_url'],
                $config['remote_soap_user'],
                $config['remote_soap_pass'],
                ['map_uids' => false, 'validateCert' => $config['soap_validate_cert']]
            );
            #echo ("Variável <$login>\n");
            $b = $a->userDataWithUIDFromIspc($email);
            #var_dump($b);
            //Disable the user if can't use BOTH imap and pop3
            if (($b['disableimap'] == "y") && ($b['disablepop3'] == "y"))
                $b = false;
            if ($b) {
                // Split name safely
                $nameParts = Util::splitName($b['name']);
                $givenName = $nameParts[0] ?? $b['name'];
                $surname = $nameParts[1] ?? '';

                // Use proper DN for LDAP entry (not email)
                $dn = Util::getDNfromMail($email);

                $entries = new Entries(
                    Entry::create($dn, [
                        'cn' => $b['name'], #Full name
                        'sn' => $surname, #surname
                        'uid' => Util::getUsernameFromMail($email),
                        'sAMAccountName' => Util::getUsernameFromMail($email), #SAM name should be username, not DN
                        'givenName' => $givenName, #first name
                        'mail' => $email,
                    ])
                );
                //var_dump($entries);
                return $entries;
            }
        } else {
            //echo "nao é instancia " . get_class($filter) . "\n";
        }
        // Do your logic here with the search request, return entries...
        #           return new Entries();
        $entries = new Entries(
            /*   Entry::create('cn=Foo,dc=FreeDSx,dc=local', [
                'cn' => 'Foo',
                'sn' => 'Bar',
                'givenName' => 'Foo',
            ]),
            Entry::create('cn=Chad2,dc=FreeDSx,dc=local', [
                'cn' => 'Chad',
                'sn' => 'Sikorra',
                'givenName' => 'Chad',
            ])*/);

        #           var_dump($entries);
        return $entries;
    }
}
