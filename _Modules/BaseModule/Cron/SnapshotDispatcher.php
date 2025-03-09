<?php
namespace Module\BaseModule\Cron;

use Controllers\Panel;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Controllers\ClusterHelper;
use Module\BaseModule\Controllers\Server;
use Module\BaseModule\Database\Models\SnapshotModel;

class SnapshotDispatcher {

    /**
     * order of execution:
     * 
     * 1. delete expired snapshots
     * 2. create snapshots where state is pending (can be done async)
     * 
     * @return void
     */
    public static function execute() {

        // 1. mark snapshots as deleted where expiration is set
        self::deleteExpired();

        // 2. create pending snapshots
        self::createSnapshots();
    }

    public static function deleteExpired() {
        $days = Settings::getConfigEntry('SNAPSHOT_RETENTION', 30);

        $toDeletes = array_map(
            fn($e) => SnapshotModel::fromArray($e),
            Panel::getDatabase()->custom_query("SELECT * FROM snapshots WHERE createdAt < DATE_SUB(NOW(), INTERVAL $days DAY)")->fetchAll(\PDO::FETCH_ASSOC)
        );

        foreach ($toDeletes as $toDelete) {
            $server           = Server::loadServer($toDelete->serverId);
            $toDelete->status = "deleting";
            $toDelete->save();
            $result = ClusterHelper::deleteSnapshot(
                $server->node,
                $server->vmid,
                $toDelete->name
            );
            if ($result) {
                Panel::getDatabase()->delete('snapshots', 'id', $toDelete->id);
            }
        }
    }

    public static function createSnapshots() {
        $pendings = array_map(
            fn($e) => SnapshotModel::fromArray($e),
            Panel::getDatabase()->fetch_multi_row('snapshots', ['*'], ['status' => 'pending'])->fetchAll(\PDO::FETCH_ASSOC)
        );
        $tasks    = [];
        foreach ($pendings as $pending) {
            $server = Server::loadServer($pending->serverId);
            $result = ClusterHelper::createSnapshot(
                $server->node,
                $server->vmid,
                $pending->name,
                filter_var($pending->includeMemory, FILTER_VALIDATE_BOOL),
                true
            );
            if ($result) {
                $pending->status = "creating";
                $pending->save();
                $tasks[] = ['node' => $server->node, 'taskId' => $result, 'pending' => $pending];
            }
        }

        while (!empty($tasks)) {
            $tasks = array_filter($tasks, function ($task) {
                $status = ClusterHelper::getTaskStatus($task['node'], $task['taskId']);
                if ($status) {
                    $task['pending']->status = "available";
                    $task['pending']->save();
                    return false;
                }
                return true;
            });
        }
    }

}