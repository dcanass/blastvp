<?php

namespace Module\BaseModule\Controllers;

use Controllers\MailHelper;
use Controllers\Panel;
use EmailAuth\Discover;
use GuzzleHttp\Client;
use Migration\MigrationHandler;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Controllers\IPAM\IPAM;
use Module\BaseModule\Controllers\IPAM\IPAMHelper;
use Monolog\Handler\StreamHandler;
use Objects\Constants;
use Objects\Invoice;
use Objects\Permissions\ACL;
use Objects\Permissions\Resources\UserResource;
use Objects\Ticket;
use Objects\User;
use PayPal\Api\Payment;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;
use ProxmoxVE\Proxmox;
use Stripe\StripeClient;
use VisualAppeal\AutoUpdate;
use ZipArchive;

class Admin
{
    public static function admin_users()
    {
        $user = BaseModule::getUser();

        if (!ACL::can($user)->read(UserResource::class, 0)) {
            http_response_code(401);
            header('Location: ' . APP_URL);
        }

        Panel::compile(
            '_views/_pages/admin/users.html',
            Panel::getLanguage()->getPages(['global', 'admin_users'])
        );
    }

    public static function admin_user($id)
    {
        $_user = BaseModule::getUser();

        if (!ACL::can($_user)->read(UserResource::class, 0)) {
            http_response_code(401);
            header('Location: ' . APP_URL);
        }

        $user = (new User($id))->load();
        if (!$user) {
            die('404');
        }

        $servers        = $user->getServers();
        $tickets        = Ticket::loadAllTickets($user->getId());
        $open_tickets   = Ticket::filterOpenTickets($tickets);
        $closed_tickets = Ticket::filterClosedTickets($tickets);

        $invoices = $user->getBalance()->loadInvoices()->getInvoices();
        $address  = $user->getAddress()->load();
        Panel::compile('_views/_pages/admin/user.html', array_merge([
            "_user"          => $_user,
            "user"           => $user,
            "servers"        => $servers,
            "open_tickets"   => $open_tickets,
            "closed_tickets" => $closed_tickets,
            "invoices"       => $invoices,
            "address"        => (array) $address
        ], Panel::getLanguage()->getPages(['global', 'settings','admin_user'])));
    }

    public static function createUser()
    {
        $name  = $_POST['name'];
        $email = $_POST['email'];

        $password = bin2hex(random_bytes(15));

        // check if user already exists
        if (Panel::getDatabase()->fetch_single_row('users', 'email', $email)) {
            self::returnJson(['error' => true, 'message' => Panel::getLanguage()->get('register', 'm_already_taken')]);
        }

        Panel::getDatabase()->insert('users', [
            'username' => $name,
            'email'    => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT)
        ]);
        $user = Panel::getDatabase()->fetch_single_row('users', 'email', $email);
        Panel::getDatabase()->insert('api-tokens', [
            'userId'      => $user->id,
            'token'       => Server::getRandomId(125),
            'description' => "SYSTEM"
        ]);

        self::returnJson(['error' => false, 'message' => Panel::getLanguage()->get('admin_users', 'm_created'), 'password' => $password]);
    }

    public static function returnJson($c)
    {
        header('Content-Type: appplication/json');
        die(json_encode($c));
    }

    public static function setRank($userid, $rank)
    {
        $user = BaseModule::getUser();

        if ($user->getPermission() <= 2) {
            die('401');
        }
        if ($user->getPermission() <= 2 && $rank == 3) {
            die('401');
        }

        Panel::getDatabase()->update('users', ['permission' => $rank], 'id', $userid);

        die();
    }

    public static function addBalance($userid)
    {
        $user = BaseModule::getUser();

        if ($user->getPermission() < 2) {
            die('401');
        }

        $amount = floatval(str_replace(',', '.', $_POST['value']));

        $user = (new User($userid))->load();
        $b    = $user->getBalance();
        $b->addBalance($amount);
        $b->save();

        $b->insertInvoice(
            $amount,
            Invoice::CREDIT,
            $user->getId(),
            1,
            Invoice::getNextId()
        );

        die('ok');
    }

    public static function removeBalance($userid)
    {
        $user = BaseModule::getUser();

        if ($user->getPermission() < 2) {
            die('401');
        }

        $amount = floatval(str_replace(',', '.', $_POST['value']));

        $user = (new User($userid))->load();
        $b    = $user->getBalance();
        $b->removeBalance($amount);
        $b->save();

        $b->insertInvoice(
            $amount,
            Invoice::PAYMENT,
            $user->getId(),
            1,
            Invoice::getNextId()
        );

        die('ok');
    }

    public static function deleteUser($id)
    {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die('401');
        }
        header('Content-Type: application/json');

        $user = Panel::getDatabase()->fetch_single_row('users', 'id', $id);
        if ($user->permission >= 2) {
            die('401');
        }
        // check if user has any servers that are not deleted
        $has = Panel::getDatabase()->custom_query("SELECT * FROM servers WHERE userid=? AND deletedAt IS NULL", ['userId' => $user->id])->rowCount();
        if ($has > 0) {
            die(json_encode(['error' => true, 'msg' => 'has_servers']));
        } else {
            // delete user
            Panel::getDatabase()->delete('users', 'id', $user->id);
            die(json_encode(['error' => false]));
        }
    }

    public static function changePassword($id)
    {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die('401');
        }
        header('Content-Type: application/json');

        $target = Panel::getDatabase()->fetch_single_row('users', 'id', $id);
        if ($target->permission > 1) {
            die('401');
        }

        Panel::getDatabase()->update('users', ['password' => password_hash($_POST['new'], PASSWORD_DEFAULT)], 'id', $id);
        die(json_encode(['error' => false]));
    }

    public static function onAllUpdateFinishCallbacks($updatedVersions)
    {
        $return = "";
        $return .= PHP_EOL . "Finished update, running script";
        exec("HOME=/root ./bin/after_update.sh 2>&1", $scriptOutput, $code);
        $return .= PHP_EOL . '<b>script output:</b><br />';
        $return .= PHP_EOL . implode('<br />', $scriptOutput);
        $return .= PHP_EOL . '<h3>Updated versions:</h3>';
        $return .= PHP_EOL . '<ul>';
        foreach ($updatedVersions as $v) {
            $return .= PHP_EOL . '<li>' . $v . '</li>';
        }
        $return .= PHP_EOL . '</ul>';

        return $return;
    }

    public static function update($emulate = false)
    {
        /** @var User $user */
        $user = BaseModule::getUser();
        if (php_sapi_name() !== 'cli' && $user->getPermission() < 3) {
            return ['error' => true, 'code' => 403];
        }

        // do license check here
        $productId = Panel::getModule('BaseModule')->getMeta()->productId;
        // license check
        $result = json_decode(file_get_contents("https://bennetgallein.de/api/license-check/" . $productId));
        if ($result->error) {
            $licenseInvalid = str_replace('{{ip}}', $result->ip, Panel::getLanguage()->get('global', 'm_license_invalid'));
            return [
                'error'   => true,
                'message' => $licenseInvalid
            ];
        }

        $update = new AutoUpdate(__DIR__ . "/../../../temp", __DIR__ . "/../../../", 60);
        $update->setCurrentVersion(Panel::$VERSION);
        $update->setUpdateUrl('https://bennetgallein.de/api/update/' . Panel::getModule('BaseModule')->getMeta()->productId);

        try {
            $update->addLogHandler(new StreamHandler(__DIR__ . '/../../../update.log'));
        } catch (\Exception $e) {
            return [
                'error'   => true,
                'message' => "Failed to open log. Make sure the update.log file is writeable."
            ];
        }

        if ($update->checkUpdate() === false) {
            return [
                'error'   => true,
                'message' => "Could not check for updates. Review the update.log file or try again later."
            ];
        }

        if ($update->newVersionAvailable()) {
            // Optional - empty log file
            $f = @fopen(__DIR__ . '/update.log', 'r+');
            if ($f !== false) {
                ftruncate($f, 0);
                fclose($f);
            }

            if (!$emulate) {
                $update->setOnAllUpdateFinishCallbacks('\Module\BaseModule\Controllers\Admin::onAllUpdateFinishCallbacks');
            }
            // This call will only simulate an update.
            // Set the first argument (simulate) to "false" to install the update
            // i.e. $update->update(false);
            $result = $update->update($emulate);
            if ($result === true) {
                $i            = json_decode(file_get_contents(dirname(__FILE__) . "/../module_meta.json"));
                $i->productId = $productId;
                @file_put_contents(dirname(__FILE__) . "/../module_meta.json", json_encode($i, JSON_PRETTY_PRINT));

                return [
                    'error'   => false,
                    'message' => "Update download & installation successfull. Starting Database Migration"
                ];
            } else {
                return [
                    'error'   => true,
                    'message' => "Update failed. Review the update.log file"
                ];
            }
        }
        return ['error' => false];
    }

    public static function testMailServer()
    {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            die();
        }
        $mail = Panel::getMailHelper();
        header('Content-Type: application/json');
        $mail->setAddress($user->getEmail());

        $res = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
            "m_title" => "Testmail",
            "m_desc"  => "ProxmoxCP Testmail. If you can read this it works!",
            "logo"    => Settings::getConfigEntry("LOGO")
        ]);

        $mail->setContent("ProxmoxCP Testmail", $res);
        try {
            $mail->send();
        } catch (\PHPMailer\PHPMailer\Exception $e) {

            die(json_encode(['error' => true, 'message' => Panel::getLanguage()->get('admin_settings', 'm_mail_error'), 'trace' => $e->getMessage()]));
        }
        $mail->clear();
        die(json_encode(['error' => false, 'message' => Panel::getLanguage()->get('admin_settings', 'm_mail_noerror')]));
    }

    public static function discoverEmail()
    {
        header("Content-Type: application/json");
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            die('401');
        }
        $disover = new Discover();

        die(json_encode(['result' => $disover->smtp($_POST['email'])]));
    }

    public static function testPayPal()
    {
        $public  = $_POST['public'];
        $private = $_POST['private'];
        $mode    = $_POST['mode'];
        header("Content-Type: application/json");

        $backend = $mode == "sandbox" ? Constants::PAYPAL_MODES["sandbox"]["backend"] : Constants::PAYPAL_MODES["production"]["backend"];

        $apiContext = new ApiContext(
            new OAuthTokenCredential(
                $public,
                $private
            )
        );
        $apiContext->setConfig([
            "mode" => $backend
        ]);

        $params = array('count' => 1);

        try {
            $res = Payment::all($params, $apiContext);
        } catch (\Exception $e) {
            die(json_encode(['error' => true, 'message' => Panel::getLanguage()->get('admin_settings', 'm_paypal_error'), 'trace' => $e->getMessage()]));
        }
        die(json_encode(['error' => false, 'message' => Panel::getLanguage()->get('admin_settings', 'm_paypal_noerror')]));
    }

    public static function testStripe()
    {
        $public  = $_POST['public'];
        $private = $_POST['private'];
        header('Content-Type: application/json');

        try {
            $stripe    = new StripeClient($private);
            $customers = $stripe->customers->all([]);
        } catch (\Exception $e) {
            die(json_encode(['error' => true, 'message' => Panel::getLanguage()->get('admin_settings', 'm_paypal_error'), 'trace' => $e->getMessage()]));
        }

        die(json_encode(['error' => false, 'message' => Panel::getLanguage()->get('admin_settings', 'm_paypal_noerror')]));
    }

    public static function testProxmox()
    {
        $method           = $_POST['method'];
        $tokenId          = $_POST['tokenId'];
        $tokenSecret      = $_POST['tokenSecret'];
        $host             = $_POST['host'];
        $user             = $_POST['user'];
        $password         = $_POST['password'];
        $bridge           = $_POST['bridge'];
        $storage          = $_POST['storage'];
        $node             = $_POST['node'];
        $skip_lock        = $_POST['skiplock'];
        $console_host     = $_POST['console_host'];
        $console_password = $_POST['console_password'];
        $console_port     = $_POST['console_port'];

        header('Content-Type: application/json');

        // 1. check if credentials work
        // 2. list all nodes and check if specified exists
        // 3. check if bridge is available
        // 4. check if storage is available -> load %
        // 5. list all available kvm templates
        // 6. check node limit configuration
        // 7. check if vnc is configured and working by writing a test-entry
        // 8. check for IPAM
        $defaults = [
            // state either error, warning, success or skipped
            'state'   => 'skipped',
            'message' => ""
        ];
        $return   = [
            'connection' => [
                'display' => Panel::getLanguage()->get('admin_settings', 'm_mail_test'),
                ...$defaults
            ],
            'nodes'      => [
                'display' => Panel::getLanguage()->get('admin_host', 'm_title'),
                ...$defaults
            ],
            'networking' => [
                'display' => Panel::getLanguage()->get('server', 'm_network_information'),
                ...$defaults
            ],
            'storage'    => [
                'display' => Panel::getLanguage()->get('server', 'm_disk'),
                ...$defaults
            ],
            'templates'  => [
                'display' => Panel::getLanguage()->get('global', 'm_settings_templates'),
                ...$defaults
            ],
            'node-limit' => [
                'display' => Panel::getLanguage()->get('admin_host', 'm_provisioned_usage'),
                ...$defaults
            ],
            'vnc'        => [
                'display' => explode(' ', Panel::getLanguage()->get('admin_settings', 'm_vnc_user'))[0],
                ...$defaults
            ],
            'ipam'       => [
                'display' => Panel::getLanguage()->get('global', 'm_settings_ips'),
                ...$defaults
            ]
        ];


        $hasProxmoxConnection = false;
        $hasNodeConnection    = false;
        /**
         * 1. connection test
         */
        $conf = match ($method) {
            'api' => [
                'hostname'     => $host,
                'token-id'     => $tokenId,
                'token-secret' => $tokenSecret
            ],
            default => [
                'hostname' => $host,
                'username' => $user,
                'password' => $password
            ]
        };
        $o    = "";
        try {
            $o .= "Using " . $method . ' authentication method' . PHP_EOL . PHP_EOL;

            $p = new Proxmox($conf, 'array', new Client(['timeout' => 10]));
            $v = $p->get('/version');

            $o .= "Proxmox Version: " . $v['data']['version'];
            $return['connection']['state'] = "success";
            $hasProxmoxConnection          = true;

            $checkPermissions = $p->get('/access/permissions')['data'];
            if (sizeof($checkPermissions) == 0) {
                $o .= PHP_EOL . PHP_EOL . "WARNING: did not detect any permissions. Please make sure permissions are correct." . PHP_EOL;
                $return['connection']['state'] = "warning";
                $hasProxmoxConnection          = false;
            }
        } catch (\Exception $e) {
            $o                             = $e->getMessage();
            $return['connection']['state'] = "error";
        }
        $return['connection']['message'] = $o;


        if ($hasProxmoxConnection) {
            /**
             * 2. list all nodes and check if specified exists
             */
            $o      = "";
            $_nodes = $p->get('/nodes');
            $nodes  = array_map(function ($e) {
                return $e['node'] . ' (' . $e['status'] . ')';
            }, $_nodes['data']);

            $o .= "Found the following nodes:" . PHP_EOL;
            $o .= implode('' . PHP_EOL, $nodes);
            $isIn = array_filter($_nodes['data'], function ($e) use ($node) {
                return ($e['node'] == $node);
            });
            $o .= PHP_EOL;
            if (sizeof($isIn) > 0) {
                $return['nodes']['state'] = "success";
                $o .= "Found $node in cluster";
                $hasNodeConnection        = true;
            } else {
                $return['nodes']['state'] = "error";
                $o .= "Node \"$node\" NOT found in the cluster!";
            }
            $return['nodes']['message'] = $o;
        }

        /**
         * 3. check if bridge is available
         */
        if ($hasNodeConnection) {
            $o        = "";
            $networks = $p->get("/nodes/$node/network")['data'];
            $o .= "Found the following Interfaces: " . PHP_EOL;
            $nt       = array_map(function ($net) {
                return 'Name: ' . $net['iface'] . ', Type: ' . $net['type'];
            }, $networks);

            $o .= implode('' . PHP_EOL, $nt);

            $o .= PHP_EOL . PHP_EOL;
            $hasNetwork = array_filter($networks, function ($net) use ($bridge) {
                return $net['iface'] == $bridge;
            });

            if (sizeof($hasNetwork) > 0) {
                $o .= "Found $bridge in cluster";
                $return['networking']['state'] = "success";
            } else {
                $o .= "Interface $bridge does NOT exist!!!";
                $return['networking']['state'] = "error";
            }
            $return['networking']['message'] = $o;
        }

        /**
         * 4. check if storage is available -> load %
         */
        if ($hasNodeConnection) {
            $o        = "";
            $storages = $p->get("/nodes/{$node}/storage", ['enabled' => 1])['data'];
            $o .= "Found the following Storages: " . PHP_EOL;
            $st       = array_map(function ($s) {
                return 'Name: ' . $s['storage'] . ', % Full: ' . number_format($s['used_fraction'] * 100, 2) . PHP_EOL . ' Used for: ' . $s['content'];
            }, $storages);

            $o .= implode('' . PHP_EOL, $st);

            $existsAndValid = array_filter($storages, function ($st) use ($storage) {
                return ($st['storage'] == $storage && strpos($st['content'], 'images') !== false);
            });

            $o .= PHP_EOL . PHP_EOL;

            if (sizeof($existsAndValid) > 0) {
                $return['storage']['state'] = "success";
                $o .= "Found $storage and $storage can save disks";
            } else {
                $return['storage']['state'] = "error";
                $o .= "Storage $storage DOES NOT exist or can save NO disks";
            }
            $return['storage']['message'] = $o;
        }

        /**
         * 5. list all available kvm templates
         */
        if ($hasNodeConnection) {
            $o         = "";
            $templates = $p->get("nodes/{$node}/qemu");

            $templates = array_filter($templates['data'], function ($ele) {
                return isset($ele['template']) && $ele['template'] == 1;
            });
            $templates = array_map(function ($ele) {
                return '- ' . str_replace('-', ' ', $ele['name']);
            }, $templates);

            $o .= "Found the following templates: " . PHP_EOL;
            $o .= implode('' . PHP_EOL, $templates);

            $return['templates']['state'] = "success";

            if (sizeof($templates) == 0) {
                $return['templates']['state'] = "warning";

                $o = "No templates found.";
            }

            if ($user != "root" && (bool) filter_var($skip_lock, FILTER_VALIDATE_BOOLEAN)) {
                $return['templates']['state'] = "warning";
                $o .= PHP_EOL . PHP_EOL;
                $o .= "Using skip-lock with another user except root is not supported. You won't be able to delete VMs." . PHP_EOL;
            }

            $return['templates']['message'] = $o;
        }

        /**
         * 6. check node limit configuration
         */
        if ($hasNodeConnection) {
            $o             = "";
            $configuration = defined("NODE_LIMIT") ? unserialize(NODE_LIMIT) : new \stdClass();

            $o .= "Checking limit configuration:" . PHP_EOL . PHP_EOL;

            $limits = ClusterHelper::getLoad(true);

            if (sizeof($limits) == 0) {
                $o .= "No limit configuration found!" . PHP_EOL;
            }
            $available = [];
            foreach ($limits as $configuredLimits) {
                $memused  = $configuredLimits['mem_used'];
                $diskused = $configuredLimits['disk_used'];
                $o .= "Node " . $configuredLimits['node'] . ':' . PHP_EOL;
                $o .= "Calculated %-used Memory: " . number_format($memused, 2) . '%' . PHP_EOL;
                $o .= "Calculated %-used Disk: " . number_format($diskused, 2) . '%' . PHP_EOL;
                if ($configuration->{$configuredLimits['node']}) {
                    $limitForNode = $configuration->{$configuredLimits['node']};
                    $o .= "Configured Limit for this node is: " . $limitForNode . '%' . PHP_EOL;
                    if ($memused > $limitForNode || $diskused > $limitForNode) {
                        if ($memused > $limitForNode) {
                            $o .= "Memory used is OVER configured limit!" . PHP_EOL;
                        }
                        if ($diskused > $limitForNode) {
                            $o .= "Disk used is OVER configured limit!" . PHP_EOL;
                        }
                    } else {
                        $available[] = $configuredLimits['node'];
                    }
                } else {
                    $o .= "NO LIMIT CONFIGURED! This node will not be used for VM creation" . PHP_EOL;
                }
                $o .= PHP_EOL;
            }
            if (sizeof($available) > 0) {
                $o .= PHP_EOL . "Nodes that will be used for VM creation: " . implode(', ', $available);
            }
            $return['node-limit']['message'] = $o;
            $return['node-limit']['state']   = 'success';
        }

        /**
         * 7. check if vnc is configured and working by writing a test-entry
         */
        if ($console_host) {
            // host is configured
            $o        = "";
            $continue = true;
            try {
                $client = new \Predis\Client([
                    'password' => $console_password,
                    'host'     => $console_host,
                    'port'     => $console_port,
                    'timeout'  => 10
                ]);
                $client->connect();
            } catch (\Exception $e) {
                $return['vnc']['state'] = "error";
                $o .= "Failed to connect: " . $e->getMessage();
                $continue               = false;
            }
            if ($continue) {
                $res = $client->set('test', 'test');
                if ($res != 'OK') {
                    $return['vnc']['state'] = "error";
                    $o .= "VNC Host is configured but writing failed. Check password and host";
                } else {
                    $return['vnc']['state'] = "success";
                    $o .= "VNC Host is configured and working, response: " . $res;
                }
            }
            $return['vnc']['message'] = $o;
        }

        /**
         * 8. check for IPAM
         */
        $o          = "";
        $ip         = IPAMHelper::getFreeIp(4);
        $o .= "Checking IPAM:" . PHP_EOL;
        $deployment = Settings::getConfigEntry("O_IPAM_DEPLOYMENT", "ip4");
        $free4      = false;
        $free6      = false;
        $o .= "Testing for deployment type: $deployment" . PHP_EOL;
        if ($ip['error'] == true) {
            $o .= "Did NOT find a free IPv4 Adress!" . PHP_EOL;
        } else {
            $free4 = true;
            $o .= "Found free IPv4... OK" . PHP_EOL;
        }

        $ip6 = IPAMHelper::getFreeIp(6);
        if ($ip6['error'] == true) {
            $o .= "Did NOT find a free IPv6 Adress!" . PHP_EOL;
        } else {
            $free6 = true;
            $o .= "Found free IPv6... OK" . PHP_EOL;
        }
        if (!$free4 || !$free6) {
            if (($deployment == IPAM::IP4 || $deployment == IPAM::DUALSTACK) && !$free4) {
                $return['ipam']['state'] = "warning";
                $o .= "You have setup $deployment but there are no free addresses. New servers cannot be created." . PHP_EOL;
            }
            if (($deployment == IPAM::IP6 || $deployment == IPAM::DUALSTACK) && !$free6) {
                $return['ipam']['state'] = "warning";
                $o .= "You have setup $deployment but there are no free addresses. New servers cannot be created." . PHP_EOL;
            }
        } else {
            $return['ipam']['state'] = "success";
        }
        $return['ipam']['message'] = $o;

        return $return;
    }

    public static function importDatabaseChangesApi()
    {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            die('401 No Permission');
        }

        die(json_encode(self::__importDatabaseChanges()));
    }

    public static function __importDatabaseChanges()
    {
        $migrations = MigrationHandler::getInstance()->applyMigrations()['results'];

        $failed = array_filter($migrations, function ($ele) {
            return $ele['status'] === "ERR";
        });

        return [
            'output' => array_map(function ($ele) {
                return $ele['name'] . "... " . $ele['status'] . "<br />";
            }, $migrations),
            'failed' => $failed
        ];
    }

    public static function importDatabaseChanges()
    {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            die('401 No Permission');
        }

        // apply new migrations
        $res = self::__importDatabaseChanges();

        if (sizeof($res['failed']) > 0) {
            array_walk($res['failed'], function ($ele) {
                echo $ele['name'] . "... " . $ele['status'] . "<br />";
            });
            die('Error applying migration. Contact support');
        }

        header('Location: ' . Settings::getConfigEntry("APP_URL") . 'admin/settings?update=1&version=' . urlencode(Panel::$VERSION));
        die('Done / Fertig. Redirecting');
    }

    public static function importModule()
    {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            return [
                'error' => true,
                'code'  => 403
            ];
        }
        if (!is_dir('temp/module-installation')) {
            mkdir('temp/module-installation', 0777, true);
        }
        header('Content-Type: application/json');

        $downloadKey = $_POST['downloadKey'];

        // check key
        $meta = json_decode(file_get_contents("https://bennetgallein.de/api/download-information/$downloadKey"));
        if ($meta->error) {
            return ['error' => true, 'msg' => $meta->message];
        }
        if ($meta->product->product_type != 2) {
            return ['error' => true, 'msg' => "Not a Module-Key. Make sure to only install modules via this way."];
        }

        $module = file_get_contents("https://bennetgallein.de/api/download-key/$downloadKey");
        if (!$module) {
            return ['error' => true, 'msg' => "Download Key not found!"];
        }
        $random      = bin2hex(random_bytes(5));
        $writeToTemp = file_put_contents("temp/module-installation/$random", $module);
        if (!$writeToTemp) {
            unlink("temp/module-installation/$random");
            return ['error' => true, 'msg' => "Download failed! Check permissions on the temp-folder."];
        }

        $zip  = new ZipArchive();
        $open = $zip->open("temp/module-installation/$random");
        if (!$open) {
            return ['error' => true, 'msg' => "Opening downloaded file failed."];
        }
        $unzip = $zip->extractTo('_Modules/');
        $zip->close();
        if (!$unzip) {
            return ['error' => true, 'msg' => "File could not be unzipped. Check permissions."];
        }
        unlink("temp/module-installation/$random");

        return [
            'error' => false,
            'msg'   => 'Module installed.',

        ];
    }

    public static function getServersByNode($node)
    {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            die('401');
        }
        die(json_encode([
            Panel::getDatabase()->custom_query("SELECT * FROM servers WHERE deletedAt IS NULL AND node=?", ['node' => $node])->fetchAll()
        ]));
    }

    public static function serverMigration()
    {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            die('401');
        }

        $res = ClusterHelper::migrateVM(
            $_POST['source'],
            $_POST['target'],
            $_POST['vmid'],
            filter_var($_POST['with-local-disks'], FILTER_VALIDATE_BOOLEAN),
            filter_var($_POST['live-migration'], FILTER_VALIDATE_BOOLEAN),
            filter_var($_POST['reattach-cloud-init'], FILTER_VALIDATE_BOOLEAN)
        );

        if (isset($res['success']) && $res['success'] == true) {
            Panel::getDatabase()->update('servers', ['node' => $_POST['target']], 'vmid', $_POST['vmid']);
        }
        die(json_encode(['output' => $res]));
    }

    public static function adminInvoices()
    {
        $user = BaseModule::getUser();

        if (!ACL::can($user)->read(UserResource::class, 0)) {
            http_response_code(401);
            header('Location: ' . APP_URL);
        }

        Panel::compile(
            '_views/_pages/admin/invoices.html',
            Panel::getLanguage()->getPages(['global', 'invoices', 'admin_invoices'])
        );
    }

}
