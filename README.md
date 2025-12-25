# Gumroad API WordPress Plugin
<br><br>
<a href="https://github.com/sponsors/sinanisler">
<img src="https://img.shields.io/badge/Consider_Supporting_My_Projects_❤-GitHub-d46" width="300" height="auto" />
</a>
<br><br>

A WordPress plugin that automatically creates user accounts when customers purchase products from your Gumroad store.

## Features

- **Automatic User Creation** - Creates WordPress accounts automatically when customers buy from Gumroad
- **Webhook Support** - Real-time processing via Gumroad webhooks
- **Cron Job Monitoring** - Regular checks for new sales via API
- **Role Assignment** - Set default user roles or assign specific roles based on products
- **Welcome Emails** - Send customizable HTML welcome emails with login credentials
- **Activity Logging** - Track all API activities and user creations
- **Password Reset Links** - Automatically include password reset URLs in welcome emails

## Dynamic Email Tags

Customize welcome emails with these tags:
- `{{site_name}}` - Your site name
- `{{site_url}}` - Your site URL
- `{{product_name}}` - Purchased product name
- `{{username}}` - Generated username
- `{{password}}` - Generated password
- `{{email}}` - User's email
- `{{login_url}}` - WordPress login URL
- `{{password_reset_url}}` - Password reset link

## Settings

- **Connection** - Configure Gumroad API access token and webhook URL
- **User Roles** - Set default roles and product-specific role assignments
- **Welcome Email** - Customize email subject and HTML template
- **Cron Settings** - Configure check intervals and sales limits
- **Logs** - View and manage API activity logs
