# High Altitude Balloon APRS API
PHP-based API of High Altitude Balloon APRS Tracker.
Requires [Python-based tracker](https://github.com/mkbodanu4/high-altitude-balloon-aprs-tracker) to process packets from APRS-IS servers.

Feel free to use this application standalone or with [WordPress Plugin](https://github.com/mkbodanu4/high-altitude-balloon-aprs-plugin).

## Requirements

PHP 7.0 or higher

## API Installation

1. Upload code to your server or hosting.
2. Rename .env_example to .env
3. Update configurations in .env to your own.
4. Install [Tracker](https://github.com/mkbodanu4/high-altitude-balloon-aprs-tracker)
5. Optionally install WordPress Plugin to your WordPress site.

## Telegram bot installation

1. Install API using instructions above
2. Install additional tables (*users*, *notifications*) to database from database.sql
3. Create new Telegram bot using @BotFather
4. Save bot's API Key into .env file
5. Set up custom Secret Token and app URL as well
6. Open setup.php in your browser to install webhook URL at Telegram API side. You must see message "Ok" on that page.
7. Edit all details about your bot at @BotFather.
8. Now bot must be fully functional

## Viber bot installation

1. Install API using instructions above
2. Install additional tables (*viber_users*, *viber_notifications*) to database from database.sql
3. Create new Viber bot using at [Viber Admin Panel](https://partners.viber.com/account/create-bot-account)
4. Save bot's API Key into .env file
5. Open viber_setup.php in your browser to install webhook URL at Viber API side. You must see message "Ok" on that page.
6. Installation done, open Settings -> My Bots -> {Bot Title} to start using bot
