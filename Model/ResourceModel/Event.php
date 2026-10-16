<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\ResourceModel;

use Angeo\AgentActivity\Model\EventRepository;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for angeo_agent_event (used by the admin event log grid).
 */
class Event extends AbstractDb
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(EventRepository::TABLE, 'event_id');
    }
}
