## [2.0.11](https://github.com/conekta/conekta-magento2/releases/tag/v2.0.11) - 2019-05-23
### Added
- Add Compatibility to magento 2.3

## [2.0.10](https://github.com/conekta/conekta-magento2/releases/tag/v2.0.10) - 2019-05-23
### Added
- Added reference_id to SPEI, OXXO and CARD charge

### Fixed
- PHP code standards with PHPCS
- Sends Billing Address
- Error when simple and configurable both have prices in Magento > 2.2.8

## [2.0.9](https://github.com/conekta/conekta-magento2/releases/tag/v2.0.9) - 2019-05-23
### Added
- Added reference_id to SPEI, OXXO and CARD charge

## [2.0.8](https://github.com/conekta/conekta-magento2/releases/tag/v2.0.8) - 2019-05-01
### Fix
- Enable webhook notification per website

## [2.0.7](https://github.com/conekta/conekta-magento2/releases/tag/v2.0.7) - 2019-04-28
### Fix
- Avoid PHP Notice Errors and catch if signature is not valid

## [2.0.6](https://github.com/conekta/conekta-magento2/releases/tag/v2.0.6) - 2019-04-28
### Fix
- Enable Signature Key validation on Webhook
- Create invoice on wenhook notification

### Added
- Now the ordes are created with order_status defined in payment.xml
- Created a plugin to use order_status config data insted of default Magento processing status

## [2.0.5](https://github.com/conekta/conekta-magento2/releases/tag/v2.0.5) - 2019-04-19
### Fix
- Allow live and test private_api_key to be eddited in website scope
- Allow sandbox_mode to be eddited in website scope

## [2.0.4](https://github.com/conekta/conekta-magento2/releases/tag/v2.0.4) - 2019-03-20
### Fix
- Fix Front controller reached 100 router match iterations

## [2.0.2](https://github.com/conekta/conekta-magento2/releases/tag/v2.0.2) - 2018-10-09
### Fix
- Fix on webhook bug

## [2.0.1](https://github.com/conekta/conekta-magento2/releases/tag/v2.0.1) - 2017-08-31
### Fix
- Hotfix at instantiation of webhook observer

## [2.0.0](https://github.comhttps://github.com/conekta/conekta-magento2/releases/tag/v2.0.1/conekta/conekta-magento2/releases/tag/2.0.0) - 2017-07-26
### Remove
- Remove charge and implement order structure for api 2 in Card, Oxxo and Spei
- Put all functions on Config file to access from Oxxo, Card and Spei
### Feature
- Admin field [expiry days] for oxxo and spei
### Fix
- button.html bug fixed (issue #9)
- Code fixed to 80 Cols

## [1.1.0]() - 2016-04-06
### Change
- Major refactor
### Remove
- Remove/Deprecate Banorte PM
### Fix
- Fix PCI issue

## [1.0.0](https://github.com/conekta/conekta-magento2/releases/tag/1.0.0) - 2016-08-31
### Update
- Beta version with all payment methods
