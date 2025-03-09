<?php

namespace Module\BaseModule\Database\Models;

use Controllers\Panel;
use Module\BaseModule\Database\BaseModel;


class SnapshotModel extends BaseModel {
    protected string $table = 'snapshots';

    public function extendArray(): array {
        $status = $this->status;
        return [
            '_status' => Panel::getLanguage()->get('server', "snapshot_status_$status")
        ];
    }
}