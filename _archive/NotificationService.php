<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/InventoryService.php';

class NotificationService extends BaseService
{
    private InventoryService $inventory;

    public function __construct()
    {
        parent::__construct();
        $this->inventory = new InventoryService();
    }

    public function getLowStockNotifications(): array
    {
        $alerts = $this->inventory->getLowStockAlerts();
        $notifications = [];
        foreach ($alerts as $alert) {
            $notifications[] = [
                'type' => $alert['stock_status'] === 'out_of_stock' ? 'danger' : 'warning',
                'message' => $alert['product_name'] . ' — ' . ($alert['stock_status'] === 'out_of_stock' ? 'Out of stock' : 'Low stock: ' . $alert['total_stock'] . ' remaining'),
            ];
        }
        return $notifications;
    }
}
