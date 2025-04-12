<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ably\AblyRest;

class MonitorMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'messages:monitor';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor messages table for changes';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting message monitor...');

        $lastCheckTime = now();

        while (true) {
            try {
                // Buscar mensajes nuevos
                $newMessages = DB::table('messages')
                    ->where('created_at', '>', $lastCheckTime)
                    ->get();

                // Buscar actualizaciones de estado
                $newStatuses = DB::table('message_statuses')
                    ->where('created_at', '>', $lastCheckTime)
                    ->get();

                if ($newMessages->count() > 0 || $newStatuses->count() > 0) {
                    $this->publishUpdates($newMessages, $newStatuses);
                }

                $lastCheckTime = now();
                sleep(1); // Esperar 1 segundo

            } catch (\Exception $e) {
                $this->error('Error: ' . $e->getMessage());
                sleep(5); // Esperar 5 segundos antes de reintentar
            }
        }
    }

    /**
     * Publish updates to Ably
     *
     * @param \Illuminate\Support\Collection $messages
     * @param \Illuminate\Support\Collection $statuses
     * @return void
     */
    private function publishUpdates($messages, $statuses)
    {
        try {
            $ably = new AblyRest(env('ABLY_KEY'));

            // Publicar mensajes nuevos
            foreach ($messages as $message) {
                $channel = $ably->channel("chat-{$message->user_id}");
                $channel->publish('new-message', [
                    'id' => $message->id,
                    'content' => $message->content,
                    'direction' => $message->direction,
                    'status' => $message->status,
                    'created_at' => $message->created_at,
                    'contact_id' => $message->contact_id
                ]);
            }

            // Publicar actualizaciones de estado
            foreach ($statuses as $status) {
                $channel = $ably->channel("status-{$status->meta_message_id}");
                $channel->publish('status-update', [
                    'meta_message_id' => $status->meta_message_id,
                    'status' => $status->status,
                    'timestamp' => $status->status_timestamp
                ]);
            }

            if ($messages->count() > 0) {
                $this->info("Published {$messages->count()} new messages");
            }

            if ($statuses->count() > 0) {
                $this->info("Published {$statuses->count()} status updates");
            }

        } catch (\Exception $e) {
            $this->error('Error publishing to Ably: ' . $e->getMessage());
        }
    }
}
