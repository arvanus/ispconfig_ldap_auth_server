<?php

//namespace LdapRequestHandler;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/freedsx/ldap/src/FreeDSx/Ldap/LdapServer.php';
require_once __DIR__ . '/../vendor/freedsx/ldap/src/FreeDSx/Ldap/Server/RequestHandler/GenericRequestHandler.php';
require_once __DIR__ . '/../vendor/freedsx/ldap/src/FreeDSx/Ldap/Server/RequestContext.php';
require_once __DIR__ . '/../vendor/freedsx/ldap/src/FreeDSx/Ldap/Operation/Request/SearchRequest.php';
require_once __DIR__ . '/../vendor/freedsx/ldap/src/FreeDSx/Ldap/Entry/Entries.php';
require_once __DIR__ . '/../vendor/freedsx/ldap/src/FreeDSx/Ldap/Entry/Entry.php';
require_once __DIR__ . '/../vendor/freedsx/ldap/src/FreeDSx/Ldap/Exception/OperationException.php';
require_once __DIR__ . '/../vendor/freedsx/ldap/src/FreeDSx/Ldap/Operation/Request/SearchRequest.php';
require_once __DIR__ . '/../vendor/freedsx/ldap/src/FreeDSx/Ldap/Search/Filter/AndFilter.php';
require_once __DIR__ . '/../vendor/freedsx/ldap/src/FreeDSx/Ldap/Search/Filter/EqualityFilter.php';
require_once __DIR__ . '/user_ispconfig.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/../config/config.php';

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
        echo "bind: <$username>\n";

        #Verify if domain is in allowed list
        if ((isset($config['accept_domain_only'])) && (count($config['accept_domain_only']) > 0)) {
            if (!in_array(strtolower(Util::getDomainFromMail($username)),  array_map('strtolower', $config['accept_domain_only'])))
                return false;
        }

        $a = new \OC_User_ISPCONFIG(
            $config['soap_location'],
            $config['soap_url'],
            $config['remote_soap_user'],
            $config['remote_soap_pass'],
            ['map_uids' => false, 'validateCert' => $config['soap_validate_cert']]
        );
        $b = $a->checkPassword($username, $password);
        //echo $b."\n";
        return !!$b;
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
                $entries = new Entries(
                    Entry::create($email, [
                        'cn' => $b['name'], #Full name?
                        'sn' => Util::splitName($b['name'])[1], #surname
                        'uid' => Util::getUsernameFromMail($email),
                        'sAMAccountName' => Util::getDNfromMail($email),
                        'givenName' => Util::splitName($b['name'])[0], #name
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
