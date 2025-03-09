<?php
namespace Module\BaseModule\Controllers\Server;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Controllers\ClusterHelper;
use Module\BaseModule\Controllers\Server;
use Module\BaseModule\Database\Models\SnapshotModel;
use Objects\Permissions\ACL;
use Objects\Permissions\Resources\ServerResource;

class Snapshots {


    public static function listSnapshots(float $id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->read(ServerResource::class, $id))
            return [
                'code' => 403
            ];

        $snapsshots = array_map(
            fn($e) => SnapshotModel::fromArray($e)->toArray(),
            array_reverse(Panel::getDatabase()->fetch_multi_row('snapshots', ['*'], ['serverId' => $id])->fetchAll(\PDO::FETCH_ASSOC))
        );

        return [
            'snapshots' => $snapsshots,
            'meta'      => []
        ];
    }

    public static function createSnapshot(float $id) {
        $user = BaseModule::getUser();
        $b    = Panel::getRequestInput();
        if (!ACL::can($user)->update(ServerResource::class, $id))
            return [
                'code' => 403
            ];

        $limit = Settings::getConfigEntry('SNAPSHOT_LIMIT', 10);
        // validate uniqueness of snapshot name & limit
        $snapshotCount = Panel::getDatabase()->fetch_multi_row('snapshots', ['*'], ['serverId' => $id])->rowCount();
        if ($snapshotCount >= $limit) {
            return [
                'code'    => 400,
                'error'   => true,
                'message' => Panel::getLanguage()->get('server', 'snapshot_limit_reached')
            ];
        }

        // check if name already exists
        $nameExists = Panel::getDatabase()->fetch_multi_row('snapshots', ['*'], ['serverId' => $id, 'name' => $b['name']])->rowCount();
        if ($nameExists > 0) {
            return [
                'code'    => 400,
                'error'   => true,
                'message' => Panel::getLanguage()->get('server', 'snapshot_duplicate_name')
            ];
        }


        $snapshotId = SnapshotModel::fromArray([
            'serverId'      => $id,
            'description'   => $b['description'],
            'name'          => $b['name'],
            'includeMemory' => filter_var($b['includeMemory'], FILTER_VALIDATE_BOOL) ? 1 : 0,
            'status'        => 'pending'
        ])->save();

        return ['error' => false];
    }

    public static function deleteSnapshot($id) {
        $user = BaseModule::getUser();
        $b    = Panel::getRequestInput();
        if (!ACL::can($user)->update(ServerResource::class, $id))
            return [
                'code' => 403
            ];
        $server = Server::loadServer($id);

        $snap = SnapshotModel::fromId($b['snapshotId']);

        $result = ClusterHelper::deleteSnapshot(
            $server->node,
            $server->vmid,
            $snap->name,
        );

        $snap->remove();

        return ['error' => false];
    }

    public static function restoreSnapshot($id): array {
        $user = BaseModule::getUser();
        $b    = Panel::getRequestInput();
        if (!ACL::can($user)->update(ServerResource::class, $id))
            return [
                'code' => 403
            ];
        $server   = Server::loadServer($id);
        $snapshot = SnapshotModel::fromId($b['snapshotId']);

        ClusterHelper::restoreSnapshot(
            $server->node,
            $server->vmid,
            $snapshot->name
        );

        return [
            'error' => false
        ];
    }
}