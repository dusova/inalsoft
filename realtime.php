<?php
// Gerçek zamanlı güncelleme için WebSocket bağlantısı kurma ve yönetme

// Composer ile vendor klasörünü dahil et
require __DIR__ . '/vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory;
use React\Socket\SecureServer;
use React\Socket\Server;

/**
 * WebSocket sunucusu için MessageComponentInterface uygulaması
 */
class RealTimeServer implements \Ratchet\MessageComponentInterface {
    protected $clients;
    protected $users = [];

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        echo "Gerçek zamanlı sunucu başlatıldı!\n";
    }

    public function onOpen(\Ratchet\ConnectionInterface $conn) {
        // Yeni bir bağlantı oluşturulduğunda
        $this->clients->attach($conn);
        echo "Yeni bağlantı! ({$conn->resourceId})\n";
    }

    public function onMessage(\Ratchet\ConnectionInterface $from, $msg) {
        // Bir istemciden mesaj alındığında
        $data = json_decode($msg, true);
        
        // Kullanıcı kimlik doğrulaması
        if (isset($data['type']) && $data['type'] === 'auth') {
            $this->users[$from->resourceId] = [
                'user_id' => $data['user_id'],
                'username' => $data['username']
            ];
            echo "Kullanıcı {$data['username']} kimliği doğrulandı\n";
            return;
        }
        
        // İşleme/Değişiklik bildirimi
        if (isset($data['type']) && $data['type'] === 'update') {
            // Mesajı diğer tüm bağlı kullanıcılara ilet
            foreach ($this->clients as $client) {
                if ($from !== $client) {
                    // Göndereni hariç tut ve mesajı diğer kullanıcılara ilet
                    $client->send(json_encode([
                        'type' => 'update',
                        'entity' => $data['entity'],
                        'action' => $data['action'],
                        'data' => $data['data'],
                        'user' => isset($this->users[$from->resourceId]) ? $this->users[$from->resourceId] : null
                    ]));
                }
            }
        }
    }

    public function onClose(\Ratchet\ConnectionInterface $conn) {
        // Bağlantı kapatıldığında
        $this->clients->detach($conn);
        
        if (isset($this->users[$conn->resourceId])) {
            echo "Kullanıcı {$this->users[$conn->resourceId]['username']} çıkış yaptı\n";
            unset($this->users[$conn->resourceId]);
        }
        
        echo "Bağlantı {$conn->resourceId} kapatıldı\n";
    }

    public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e) {
        // Bir hata oluştuğunda
        echo "Hata: {$e->getMessage()}\n";
        $conn->close();
    }
}

// WebSocket sunucusunu başlat
$loop = Factory::create();
$webSock = new Server('0.0.0.0:8080', $loop);
$server = new IoServer(
    new HttpServer(
        new WsServer(
            new RealTimeServer()
        )
    ),
    $webSock,
    $loop
);

echo "WebSocket sunucusu 8080 portunda çalışıyor...\n";
$server->run();

/*
Not: Bu dosyayı komut satırından çalıştırın:

php realtime.php

Ayrıca Composer ile aşağıdaki bağımlılıkları yüklemeniz gerekir:

composer require cboden/ratchet

*/