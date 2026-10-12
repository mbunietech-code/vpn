<?php

namespace App\Filament\Pages;

use App\Mail\AlertTestMail;
use App\Models\Setting;
use App\Services\Mailer;
use App\Services\PaymentAlerts;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Who gets the admin emails (payment to review, ...). Stored in the
 * `payment_alert_emails` setting; empty = every admin account.
 */
class Notifications extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 25;

    protected static ?string $title = 'Notification emails';

    protected static ?string $navigationLabel = 'Notifications';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['emails' => $this->saved()]);
    }

    /** @return list<string> */
    private function saved(): array
    {
        return collect(explode(',', (string) Setting::value('payment_alert_emails')))
            ->map(fn ($e) => trim($e))->filter()->values()->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Who gets the emails')
                    ->description('When a customer sends a payment screenshot, these addresses get an email with a link to approve it. '
                        .'Type an email and press Enter to add it; click × to remove one. Leave the list empty to send to every admin account.')
                    ->schema([
                        TagsInput::make('emails')
                            ->label('Emails')
                            ->placeholder('e.g. support@mt.co.tz, then Enter')
                            ->splitKeys(['Enter', ',', ' '])
                            ->nestedRecursiveRules(['email:rfc', 'max:120'])
                            ->reorderable(false),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save')->submit('save'),
                    ]),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Send a test email')
                ->icon('heroicon-m-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Sends a short test email to: '.implode(', ', app(PaymentAlerts::class)->recipients()).'. Save first if you changed the list.')
                ->action(fn () => $this->sendTest()),
        ];
    }

    public function save(): void
    {
        $emails = collect($this->form->getState()['emails'] ?? [])
            ->map(fn ($e) => strtolower(trim($e)))->filter()->unique()->values();

        Setting::updateOrCreate(
            ['key' => 'payment_alert_emails'],
            [
                'value' => $emails->implode(', '),
                'group' => 'alerts',
                'label' => 'Emails for "payment to review" alerts, comma separated (empty = all VPN admins)',
                'is_secret' => false,
            ],
        );
        config(['services.mvpn.payment_alert_emails' => $emails->implode(',')]);
        $this->form->fill(['emails' => $emails->all()]);

        Notification::make()->success()
            ->title('Saved')
            ->body('Alerts go to: '.implode(', ', app(PaymentAlerts::class)->recipients()))
            ->send();
    }

    private function sendTest(): void
    {
        $sent = [];
        $failed = [];
        foreach (app(PaymentAlerts::class)->recipients() as $to) {
            try {
                app(Mailer::class)->send($to, new AlertTestMail);
                $sent[] = $to;
            } catch (Throwable $e) {
                $failed[] = $to;
                report($e);
            }
        }

        Notification::make()
            ->{$failed ? 'warning' : 'success'}()
            ->title($sent ? 'Test email sent to '.implode(', ', $sent) : 'Nothing sent')
            ->body($failed ? 'Could not send to: '.implode(', ', $failed).'. Check the email settings.' : 'Check the inbox (and Spam).')
            ->send();
    }
}
