<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;

class GeneralNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $message;
    protected $meeting;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($message, $meeting = null)
    {
        $this->message = $message;
        $this->meeting = $meeting;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [TelegramChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return NotificationChannels\Telegram\TelegramMessage
     */
    public function toTelegram($notifiable)
    {
        $message = TelegramMessage::create()->token(env('TELEGRAM_BOT_TOKEN'));

        $message = $message->content($this->message);

        if ($this->meeting !== null) {
            $message = $message->button('Join Meeting', $this->meeting->zoom_url);
        }

        return $message;
    }
}
