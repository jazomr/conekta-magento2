![alt tag](https://raw.github.com/conekta/conekta-magento/master/readme_files/cover.png)

<div align="center">

Magento 2 Plugin v.2.0.4 (Stable)
========================

[![Made with PHP](https://img.shields.io/badge/made%20with-php-red.svg?style=for-the-badge&colorA=ED4040&colorB=C12C2D)](http://php.net) [![By Conekta](https://img.shields.io/badge/by-conekta-red.svg?style=for-the-badge&colorA=ee6130&colorB=00a4ac)](https://conekta.com)
</div>

Installation
-----------

1. First add this repository in your composer config

    ```bash
    composer config repositories.conekta git https://github.com/conekta/conekta-magento2.git
    ```
2. Add the dependency

    ```bash
    composer require conekta/conekta_payments dev-master
    ```
3. Update your Magento

    ```bash
    php bin/magento setup:upgrade
    ```
4. Compile your XML files

    ```bash
    php bin/magento setup:di:compile
    ```

Updates
-----------

For update this plugin execute the next command

```bash
php bin/magento setup:upgrade # version bump
php bin/magento setup:di:compile # version bump
composer update conekta/magento2-module
```

Magento Version Compatibility
-----------------------------
The plugin has been tested in Magento 2.2.0. Support is not guaranteed for untested versions.

## How to contribute to the project

1. Fork the repository

2. Clone the repository
```
    git clone git@github.com:yourUserName/conekta-magento2.git
```
3. Create a branch
```
    git checkout develop
    git pull origin develop
    # You should choose the name of your branch
    git checkout -b <feature/my_branch>
```
4. Make necessary changes and commit those changes
```
    git add .
    git commit -m "my changes"
```
5. Push changes to GitHub
```
    git push origin <feature/my_branch>
```
6. Submit your changes for review, create a pull request

   To create a pull request, you need to have made your code changes on a separate branch. This branch should be named like this: **feature/my_feature** or **fix/my_fix**.

   Make sure that, if you add new features to our library, be sure that corresponding **unit tests** are added.

   If you go to your repository on GitHub, you’ll see a Compare & pull request button. Click on that button.

***

## We are always hiring!

If you are a comfortable working with a range of backend languages (Java, Python, Ruby, PHP, etc) and frameworks, you have solid foundation in data structures, algorithms and software design with strong analytical and debugging skills, check our open positions: https://www.conekta.com/careers
