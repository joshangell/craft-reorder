# Changelog

## 1.1.2 - 2018-12-06
### Added
- Now sets `reorder.unavailable` session variable, for accessing unavailable line items in templates, when redirecting after form submit (thanks @engram-design)

## 1.1.1 - 2018-11-04
### Fixed
- Updated usage of Craft Commerce `resolveLineItem()` method for changes made in Commerce 2.0.0-beta.13 regarding setting item quantities and notes

## 1.1.0 - 2018-10-28
### Added
- Added `hasAvailableLineItems()` service method to check whether an order has any available line items
- Added new order status `OrderStatus::NoItemsAvailable`

### Fixed
- No longer tries to delete line items on a new cart when set not to retain cart
- Now ensures that, if the cart is new, items have been added to it before saving it

## 1.0.2 - 2018-10-25
### Fixed
- Ensure cart has been saved (and therefore has an ID) before attempting to resolve cart line items; fixes error in newer Craft Commerce 2 beta releases

## 1.0.1 - 2018-10-20
### Fixed
- Fixed error when a line item's note is null (thanks @engram-design)
- Ensure lineItemStatus takes into account Live status (thanks @engram-design)

## 1.0.0 - 2018-09-25
- Initial release
