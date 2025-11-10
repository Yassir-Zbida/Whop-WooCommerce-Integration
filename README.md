## Whop WooCommerce Integration

![Whop Woo](assets/readme-cover.png)

Whop WooCommerce Integration is a WordPress plugin that adds a fully featured Whop payment gateway to WooCommerce. It streamlines order fulfillment through Whop, keeps customer access in sync, and provides a polished management experience for merchants.

### Features
- Secure Whop payment gateway built on WooCommerce’s payment API.
- Admin dashboard settings with real-time validation and contextual help.
- Automatic order status synchronization between WooCommerce and Whop.
- Checkout and thank-you page styling aligned with Whop’s brand guidelines.
- Robust error handling and granular logging hooks for easier troubleshooting.

### Requirements
- WordPress 5.8 or higher.
- WooCommerce 5.0 or higher.
- PHP 7.4 or higher.
- Active Whop merchant account with API credentials.

### Installation
1. Upload the plugin directory to `/wp-content/plugins/`.
2. Activate **Whop WooCommerce Integration** from the WordPress Plugins screen.
3. Navigate to **WooCommerce → Settings → Payments → Whop** to configure the gateway.

### Configuration
1. Click **Manage** next to Whop in the Payments tab.
2. Enter your Whop API Key and choose the environment (live or sandbox).
3. Map WooCommerce order statuses to Whop fulfillment states as needed.
4. Save changes and run a test transaction to confirm connectivity.

### Development
- Admin assets are located in `assets/` and are enqueued on the plugin settings page.
- Gateway logic and API integrations live in `includes/class-gateway.php`.
- The main plugin bootstrap is `whop-woocommerce-integration.php`.
- Use `uninstall.php` to clean up options when uninstalling the plugin entirely.

To contribute, create feature branches from `main`, run WordPress Coding Standards checks, and submit pull requests with clear descriptions and testing notes.

### License
This plugin is distributed under the MIT License. See `LICENSE` (if provided) for details.