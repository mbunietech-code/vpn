<?php

namespace App\Filament\Resources\Settings\Pages;

use App\Filament\Resources\Settings\SettingResource;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Providers\SettingsServiceProvider;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Settings & Keys on one page: a tab per area (Email, Payments, SMS, Alerts,
 * App), each split into sections, all saved with one button. Values are
 * stored encrypted; a secret is never sent back to the browser — an empty
 * secret field keeps the saved value.
 */
class ManageSettings extends Page
{
    protected static string $resource = SettingResource::class;

    protected static ?string $title = 'Settings & Keys';

    /** Tab per settings group, in this order, with its icon. */
    private const TABS = [
        'email' => ['Email', Heroicon::OutlinedEnvelope],
        'payments' => ['Payments', Heroicon::OutlinedCreditCard],
        'sms' => ['SMS', Heroicon::OutlinedDevicePhoneMobile],
        'alerts' => ['Alerts', Heroicon::OutlinedBellAlert],
        'app' => ['App', Heroicon::OutlinedArrowDownTray],
    ];

    /** Sections inside a tab: key prefix => [heading, description]. */
    private const SECTIONS = [
        'mail_' => ['SMTP mailbox', 'The mailbox that sends login codes (OTP) and alerts, e.g. noreply@mbuniehub.com on smtp.hostinger.com, port 465.'],
        'brevo_' => ['Brevo (optional)', 'With a Brevo API key, emails go through Brevo instead of SMTP. They usually reach the inbox more reliably.'],
        'stripe_' => ['Stripe', 'Cards, Alipay and WeChat Pay.'],
        'cryptomus_' => ['Cryptomus', 'Crypto payments.'],
        'clickpesa_' => ['ClickPesa', 'Mobile money in Tanzania.'],
        'twilio_' => ['Twilio', 'Login codes by SMS. Email codes work without this.'],
        'anthropic_' => ['AI alerts', 'Explains network problems in plain words.'],
        'telegram_' => ['Telegram', 'Where alerts are posted.'],
        'payment_' => ['Payment review emails', 'Who is emailed when a payment needs checking.'],
    ];

    /** @var array<string,mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->initialState());
    }

    public function form(Schema $schema): Schema
    {
        $tabs = $this->settings()
            ->groupBy('group')
            ->sortBy(fn ($rows, $group) => array_search($group, array_keys(self::TABS), true) === false ? 99 : array_search($group, array_keys(self::TABS), true))
            ->map(fn (Collection $rows, string $group) => $this->tab($group, $rows))
            ->values()
            ->all();

        return $schema
            ->components([
                Tabs::make('Settings')
                    ->tabs($tabs)
                    ->persistTabInQueryString('tab')
                    ->extraAttributes(['class' => 'mvpn-settings-tabs']),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save settings')
                            ->icon(Heroicon::OutlinedCheck)
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->sticky()->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $changed = 0;

        foreach ($this->settings() as $setting) {
            $field = self::field($setting->key);
            $value = $state[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if ($setting->is_secret) {
                if ((bool) ($state[self::clearField($setting->key)] ?? false)) {
                    $value = null;
                } elseif (blank($value)) {
                    continue; // empty secret = keep the saved one
                }
            } else {
                $value = blank($value) ? null : (string) $value;
                // The field shows the .env value when nothing is saved here:
                // leaving it as it is must not copy it into the database.
                if (! self::isSaved($setting) && $value !== null && $value === self::envValue($setting)) {
                    continue;
                }
            }

            if ($value === $setting->value) {
                continue;
            }

            $setting->update(['value' => $value]);
            $changed++;

            // Which key changed and by whom - never the value.
            AuditLog::create([
                'actor' => 'admin:'.auth()->id(),
                'action' => 'setting.updated',
                'target' => 'setting:'.$setting->key,
                'meta' => ['secret' => (bool) $setting->is_secret],
            ]);
        }

        // Secret fields go back to empty: nothing secret stays in the browser.
        $this->form->fill($this->initialState());

        Notification::make()
            ->success()
            ->title($changed ? 'Settings saved' : 'Nothing changed')
            ->body($changed ? $changed.' '.Str::plural('setting', $changed).' updated.' : null)
            ->send();
    }

    private function tab(string $group, Collection $rows): Tab
    {
        [$label, $icon] = self::TABS[$group] ?? [Str::headline($group), Heroicon::OutlinedCog6Tooth];
        $set = $rows->filter(fn (Setting $s) => self::isSet($s))->count();

        $sections = $rows
            ->groupBy(fn (Setting $s) => $this->sectionKey($s->key))
            ->map(function (Collection $items, string $prefix) use ($label) {
                [$heading, $description] = self::SECTIONS[$prefix] ?? [$label, null];

                return Section::make($heading)
                    ->description($description)
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema($items->flatMap(fn (Setting $s) => $this->fields($s))->all());
            })
            ->values()
            ->all();

        if ($group === 'email') {
            array_unshift($sections, Callout::make('Login codes (OTP) are sent from here')
                ->description('Fill in the SMTP mailbox, or only a Brevo API key. Empty fields use the server .env values. Save, then ask for a code in the app to test.')
                ->info());
        }

        return Tab::make($label)
            ->icon($icon)
            ->badge($set.'/'.$rows->count())
            ->badgeColor($set === $rows->count() ? 'success' : ($set ? 'info' : 'gray'))
            ->schema($sections);
    }

    /** @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field> */
    private function fields(Setting $s): array
    {
        $configured = self::isSaved($s);
        $fromEnv = ! $configured && filled(self::envValue($s));

        $input = TextInput::make(self::field($s->key))
            ->label(Str::of($s->label)->before(' (')->toString())
            ->hint($s->key)
            ->hintColor('gray')
            ->autocomplete($s->is_secret ? 'new-password' : 'off')
            ->maxLength(2000);

        // The part of the label in brackets is a tip, shown under the field.
        $tip = Str::contains($s->label, '(') ? Str::of($s->label)->after('(')->beforeLast(')')->toString() : null;

        if ($s->is_secret) {
            $input->password()->revealable()
                ->placeholder($configured ? '•••••••• saved here' : ($fromEnv ? '•••••••• set in the server .env' : 'Not set'))
                ->helperText($configured
                    ? 'Saved and encrypted. Type a new value to replace it, or leave empty to keep it.'
                    : ($fromEnv
                        ? 'Set in the server .env file (not shown). Type a new value here to replace it.'
                        : ($tip ? ucfirst($tip).'. ' : '').'Stored encrypted.'));
        } else {
            $input->placeholder('Not set')
                ->helperText(trim(($tip ? ucfirst($tip).'. ' : '').($fromEnv ? 'From the server .env file. Change it here to override it.' : '')) ?: null);
        }

        if (in_array($s->key, ['mail_from_address', 'mail_username'], true)) {
            $input->email();
        }
        if ($s->key === 'mail_port') {
            $input->numeric()->minValue(1)->maxValue(65535);
        }
        if (Str::endsWith($s->key, '_url')) {
            $input->url();
        }

        $fields = [$input];

        if ($s->is_secret && $configured) {
            $fields[] = Toggle::make(self::clearField($s->key))
                ->label('Remove the saved '.Str::lower(Str::of($s->label)->before(' (')->toString()))
                ->helperText('The .env value is used instead.')
                ->inline();
        }

        return $fields;
    }

    private function sectionKey(string $key): string
    {
        foreach (array_keys(self::SECTIONS) as $prefix) {
            if (Str::startsWith($key, $prefix)) {
                return $prefix;
            }
        }

        return 'other';
    }

    /** @return array<string,mixed> */
    private function initialState(): array
    {
        $state = [];
        foreach ($this->settings() as $s) {
            // Plain settings show what is in use: the saved value, else the .env one.
            $state[self::field($s->key)] = $s->is_secret ? null : (self::isSaved($s) ? $s->value : self::envValue($s));
            if ($s->is_secret) {
                $state[self::clearField($s->key)] = false;
            }
        }

        return $state;
    }

    /** @return Collection<int,Setting> */
    private function settings(): Collection
    {
        return Setting::query()->orderBy('sort')->get();
    }

    /** Set here or in the server .env: the setting is in use either way. */
    private static function isSet(Setting $s): bool
    {
        return self::isSaved($s) || filled(self::envValue($s));
    }

    /** Saved on this page (in the database). */
    private static function isSaved(Setting $s): bool
    {
        return filled($s->getRawOriginal('value'));
    }

    /**
     * The value the server .env gives this setting. Only meaningful while
     * nothing is saved here (a saved value overrides config at boot).
     */
    private static function envValue(Setting $s): ?string
    {
        $paths = (array) (SettingsServiceProvider::MAP[$s->key] ?? []);
        if ($paths === []) {
            return null;
        }

        $value = config($paths[0]);
        if (is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_scalar'));
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function field(string $key): string
    {
        return 'v_'.$key;
    }

    private static function clearField(string $key): string
    {
        return 'clear_'.$key;
    }
}
