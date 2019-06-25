<?php
namespace spicyweb\reorder\events;

use craft\commerce\models\LineItem;
use yii\base\Event;

/**
 * Class CopyLineItemEvent
 *
 * @package spicyweb\reorder
 * @author Spicy Web <craft@spicyweb.com.au>
 * @since 1.1.3
 */
class CopyLineItemEvent extends Event
{
    // Properties
    // =========================================================================

    /**
     * @var LineItem The original line item model.
     */
    public $originalLineItem;

    /**
     * @var LineItem The new copied line item model.
     */
    public $newLineItem;
}
