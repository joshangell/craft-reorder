<?php
namespace spicyweb\reorder;

use yii\base\Component;

use Craft;
use craft\base\Element;
use craft\commerce\Plugin as Commerce;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;

use spicyweb\reorder\enums\LineItemStatus;
use spicyweb\reorder\events\CopyLineItemEvent;

/**
 * Class Service
 *
 * @package spicyweb\reorder
 * @author Spicy Web <craft@spicyweb.com.au>
 * @since 1.0.0
 */
class Service extends Component
{
    // Constants
    // =========================================================================
    /**
     * @event CopyLineItemEvent The event that is triggered when ReOrder
     *                          has just copied the line item over onto
     *                          the new cart
     */
    const EVENT_COPY_LINE_ITEM = 'copyLineItem';

    // Public Methods
    // =========================================================================

	/**
	 * Copies line items from an order to the user's cart.
	 *
	 * @param Order $order The order from which the line items will be copied.
	 * @param bool $allowPartial Whether to allow partially available items, e.g. if there's insufficient stock.
	 * @return bool Whether the line items were successfully copied.
	 */
	public function copyLineItems(Order $order, bool $allowPartial = false): bool
	{
		$commerce = Commerce::getInstance();

		// Confirm there's at least one available line item to copy and, if so, force-save the cart to ensure it exists.
		// It's unnecessary to account for any existing cart items for quantity-related unavailability, because if
		// that's the case then clearly the cart exists and doesn't need to be force-saved.
		$forceSaveCart = $this->hasAvailableLineItems($order);
		$cart = $commerce->getCarts()->getCart($forceSaveCart);

		foreach ($order->lineItems as $item)
		{
			$itemStatus = $this->_getLineItemStatus($item, $cart->id);
			$itemAvailable = $itemStatus === LineItemStatus::Available;
			$itemInsufficientStock = $itemStatus === LineItemStatus::InsufficientStock;
			$itemAboveMaxQty = $itemStatus === LineItemStatus::AboveMaxQty;
			$itemPartiallyAvailable = $allowPartial && ($itemInsufficientStock || $itemAboveMaxQty);

			if ($itemAvailable || $itemPartiallyAvailable)
			{
				$purchasable = $item->getPurchasable();
				$qty = $itemAboveMaxQty ? $purchasable->maxQty :
					($itemInsufficientStock ? $purchasable->stock : $item->qty);

				$lineItem = $commerce->getLineItems()->resolveLineItem(
					$cart->id,
					$purchasable->id,
					$item->options
				);
				$lineItem->note = $item->note ?? '';

				if ($lineItem->id !== null)
				{
					$lineItem->qty += $qty;
				}
				else
				{
					$lineItem->qty = $qty;
				}

				// If the item had insufficient stock but was already in the cart, its quantity will now exceed the
				// available stock and will need to be reset to the available stock.
				if ($itemInsufficientStock && $lineItem->qty > $purchasable->stock)
				{
					$lineItem->qty = $purchasable->stock;
				}

				// If the item quantity exceeded the maximum for that purchasable but was already in the cart, its
				// quantity will now exceed the maximum and will need to be reset to the maximum.
				if ($itemAboveMaxQty && $lineItem->qty > $purchasable->maxQty)
				{
					$lineItem->qty = $purchasable->maxQty;
				}

				$cart->addLineItem($lineItem);

                // Save the line item so it gets an ID
                $commerce->getLineItems()->saveLineItem($lineItem, false);

                // Raise an event to allow plugins and modules to do something
                $event = new CopyLineItemEvent([
                    'originalLineItem' => $item,
                    'newLineItem' => $lineItem,
                ]);
                $this->trigger(self::EVENT_COPY_LINE_ITEM, $event);
			}
		}

		// If the cart has items, validate and save.  This protects against the possibility of saving an empty cart,
		// which could otherwise happen with a new cart if set to allow partial reorders and no order line items are
		// available.
		if (!$cart->getIsEmpty())
		{
			return $cart->validate() && Craft::$app->getElements()->saveElement($cart, false);
		}

		// If the cart was new and no order line items were available, then it can technically be said that we were
		// successful in copying all the line items that were available to copy...
		return true;
	}

	/**
	 * Checks an order's line items and returns the unavailable items along with why they're unavailable.
	 *
	 * @param Order $order The order to check.
	 * @param int $cartId A cart ID, to check for the quantity of items already in the user's cart.
	 * return array The line items that are unavailable and why.
	 */
	public function getUnavailableLineItems(Order $order, int $cartId = null): array
	{
		$unavailableLineItems = [];

		foreach ($order->lineItems as $item)
		{
			$itemStatus = $this->_getLineItemStatus($item, $cartId);

			if ($itemStatus !== LineItemStatus::Available)
			{
				$itemData = [
					'lineItem' => $item,
					'status' => $itemStatus,
				];

				array_push($unavailableLineItems, $itemData);
			}
		}

		return $unavailableLineItems;
	}

	/**
	 * Checks whether an order has any available line items.
	 *
	 * @param Order $order The order to check.
	 * @param int $cartId A cart ID, to check for the quantity of items already in the user's cart.
	 * @return bool Whether the order has any available line items.
	 * @since 1.1.0
	 */
	public function hasAvailableLineItems(Order $order, int $cartId = null): bool
	{
		$available = false;

		foreach ($order->lineItems as $item)
		{
			if ($this->_getLineItemStatus($item, $cartId) === LineItemStatus::Available)
			{
				$available = true;
				break;
			}
		}

		return $available;
	}

	/**
	 * Checks whether a line item's purchasable is available.
	 *
	 * @param LineItem $lineItem The line item to check.
	 * @param int $cartId A cart ID, to check for the quantity of items already in the user's cart.
	 * @return string The line item status.
	 */
	private function _getLineItemStatus(LineItem $lineItem, int $cartId = null): string
	{
		$commerce = Commerce::getInstance();
		$purchasable = $lineItem->getPurchasable();

		if ($purchasable === null)
		{
			return LineItemStatus::Deleted;
		}

		if ($purchasable instanceof Element && ($purchasable->getStatus() !== Element::STATUS_ENABLED && $purchasable->getStatus() !== Element::SCENARIO_LIVE))
		{
			return LineItemStatus::Disabled;
		}

		if ($purchasable instanceof Variant)
		{
			$qty = $lineItem->qty;

			// Make sure any item quantity checks take into account what's already in the user's cart.
			if ($cartId !== null)
			{
				$cartItem = $commerce->getLineItems()->resolveLineItem(
					$cartId,
					$purchasable->id,
					$lineItem->options
				);

				// Only count the cart item quantity if the item has an ID (and is therefore actually in the cart).
				if ($cartItem->id !== null)
				{
					$qty += $cartItem->qty;
				}
			}

			$minQty = $purchasable->minQty;
			$maxQty = $purchasable->maxQty;

			if ($minQty !== null && $qty < $minQty)
			{
				return LineItemStatus::BelowMinQty;
			}

			if ($maxQty !== null && $qty > $maxQty)
			{
				return LineItemStatus::AboveMaxQty;
			}

			if (!$purchasable->hasUnlimitedStock)
			{
				$stock = (int)$purchasable->stock;

				if ($stock === 0)
				{
					return LineItemStatus::OutOfStock;
				}

				if ($qty > $stock)
				{
					return LineItemStatus::InsufficientStock;
				}
			}
		}

		return LineItemStatus::Available;
	}
}
