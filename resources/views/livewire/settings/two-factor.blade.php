<?php

use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Volt\Component;
use Livewire\Attributes\Validate;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

new class extends Component {
    public bool $twoFactorEnabled;
    public bool $requiresConfirmation;

    public bool $showVerificationStep = false;
    public bool $showRecoveryCodes = false;

    public string $qrCodeSvg = '';
    public string $manualSetupKey = '';
    public array $recoveryCodes = [];

    #[Validate('required|string|min:6|max:6')]
    public string $authCode = '';

    public function getModalConfigProperty(): array
    {
        if ($this->twoFactorEnabled) {
            return [
                'title' => 'Two-Factor Authentication Enabled',
                'description' => 'Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.',
                'buttonText' => 'Close'
            ];
        }

        if ($this->showVerificationStep) {
            return [
                'title' => 'Verify Authentication Code',
                'description' => 'Enter the 6-digit code from your authenticator app',
                'buttonText' => 'Continue'
            ];
        }

        return [
            'title' => 'Enable Two-Factor Authentication',
            'description' => 'To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app',
            'buttonText' => 'Continue'
        ];
    }

    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        abort_unless(Features::enabled(Features::twoFactorAuthentication()), Response::HTTP_FORBIDDEN);

        if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
            $disableTwoFactorAuthentication(auth()->user());
        }

        $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
        $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');

        if ($this->twoFactorEnabled) {
            $this->loadRecoveryCodes();
        }
    }

    public function enable(EnableTwoFactorAuthentication $enableTwoFactorAuthentication): void
    {
        $enableTwoFactorAuthentication(auth()->user());
        $this->fetchSetupData();
        if (!$this->requiresConfirmation) {
            $this->twoFactorEnabled = true;
        }
        $this->dispatch('show-two-factor-modal');
    }

    public function fetchSetupData(): void
    {
        $user = auth()->user();
        $this->qrCodeSvg = $user->twoFactorQrCodeSvg();
        $this->manualSetupKey = decrypt($user->two_factor_secret);
    }

    public function proceedToVerification(): void
    {
        if ($this->requiresConfirmation) {
            $this->showVerificationStep = true;
            $this->resetErrorBag();
        } else {
            $this->closeModal();
        }
    }

    public function backToSetup(): void
    {
        $this->showVerificationStep = false;
        $this->authCode = '';
        $this->resetErrorBag();
    }

    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        dd($this);
        $this->validate();
        $confirmTwoFactorAuthentication(auth()->user(), $this->authCode);
        $this->twoFactorEnabled = true;
        $this->loadRecoveryCodes();
        $this->closeModal();
        $this->dispatch('two-factor-enabled');
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $generateNewRecoveryCodes(Auth::user());
        $this->loadRecoveryCodes();
    }

    public function disable(): void
    {
        app(DisableTwoFactorAuthentication::class)(auth()->user());
        $this->twoFactorEnabled = false;
        $this->clearSetupData();
    }

    public function closeModal(): void
    {
        $this->showVerificationStep = false;
        $this->authCode = '';
        $this->resetErrorBag();

        if ($this->twoFactorEnabled) {
            $this->clearSetupData();
        }

        $this->dispatch('hide-two-factor-modal');
    }

    public function clearSetupData(): void
    {
        $this->qrCodeSvg = '';
        $this->manualSetupKey = '';
        $this->recoveryCodes = [];
    }

    public function toggleRecoveryCodes(): void
    {
        if (!$this->recoveryCodes) {
            $this->loadRecoveryCodes();
        }
        $this->showRecoveryCodes = !$this->showRecoveryCodes;
    }

    private function loadRecoveryCodes(): void
    {
        $this->recoveryCodes = json_decode(decrypt(auth()->user()->two_factor_recovery_codes), true);
    }
} ?>

<section class="w-full">
    @include('partials.settings-heading')
    <x-settings.layout :heading="__('Two Factor Authentication')"
                       :subheading="__('Manage your two-factor authentication settings')">
        <div class="flex flex-col w-full mx-auto text-sm space-y-6">
            @if(!$twoFactorEnabled)
                <div class="relative flex flex-col items-start rounded-xl justify-start space-y-4">
                    <flux:badge color="red">Disabled</flux:badge>
                    <p class="text-stone-500 dark:text-stone-400">
                        When you enable two-factor authentication, you will be prompted for a secure pin during login.
                        This pin can be retrieved from a TOTP-supported application on your phone.
                    </p>
                    <div class="w-auto">
                        <flux:button
                            variant="primary"
                            icon="shield-check"
                            wire:click="enable"
                            wire:loading.attr="disabled"
                            wire:target="enable"
                        >
                            <span wire:loading.remove wire:target="enable">{{ __('Enable 2FA') }}</span>
                            <span wire:loading wire:target="enable">{{ __('Enabling...') }}</span>
                        </flux:button>
                    </div>
                </div>
            @else
                <div class="flex flex-col space-y-4">
                    <div>
                        <flux:badge color="green">Enabled</flux:badge>
                    </div>
                    <p class="text-stone-500 dark:text-stone-400">
                        With two-factor authentication enabled, you will be prompted for a secure, random pin during
                        login,
                        which you can retrieve from the TOTP-supported application on your phone.
                    </p>

                    <div>
                        <flux:callout icon="lock-keyhole-open" color="gray" class="rounded-b-none">
                            <flux:callout.heading>2FA Recovery Codes</flux:callout.heading>
                            <flux:callout.text>
                                Recovery codes let you regain access if you lose your 2FA device. Store them in a
                                secure
                                password manager.
                            </flux:callout.text>
                        </flux:callout>
                        <div
                            class="bg-stone-100 dark:bg-stone-800 rounded-b-xl border-t-0 border border-stone-200 dark:border-stone-700 text-sm">
                            <div
                                wire:click="toggleRecoveryCodes"
                                class="h-10 group cursor-pointer flex items-center select-none justify-between px-5 text-xs"
                            >
                                <div class="relative transition-opacity duration-200"
                                    @class([
                                        'opacity-40 group-hover:opacity-60' => !$showRecoveryCodes,
                                        'opacity-60' => $showRecoveryCodes
                                    ])>
                                    @if(!$showRecoveryCodes)
                                        <span class="flex items-center space-x-1">
                                                <flux:icon.eye class="size-4"/>
                                                <span>View My Recovery Codes</span>
                                            </span>
                                    @else
                                        <span class="flex items-center space-x-1">
                                                <flux:icon.eye-off class="size-4"/>
                                                <span>Hide Recovery Codes</span>
                                            </span>
                                    @endif
                                </div>
                                @if($showRecoveryCodes)
                                    <flux:button
                                        size="xs"
                                        variant="filled"
                                        class="text-stone-600"
                                        wire:click.stop="regenerateRecoveryCodes"
                                        wire:loading.attr="disabled"
                                        wire:target="regenerateRecoveryCodes"
                                    >
                                            <span wire:loading.remove
                                                  wire:target="regenerateRecoveryCodes">{{ __('Regenerate Codes') }}</span>
                                        <span wire:loading
                                              wire:target="regenerateRecoveryCodes">{{ __('Regenerating...') }}</span>
                                    </flux:button>
                                @endif
                            </div>
                            @if($showRecoveryCodes)
                                <div class="relative">
                                    <div
                                        class="grid max-w-xl gap-1 px-4 py-4 font-mono text-sm bg-stone-200 dark:bg-stone-900 dark:text-stone-100">
                                        @forelse($recoveryCodes as $code)
                                            <div>{{ $code }}</div>
                                        @empty
                                            <div class="text-stone-500">No recovery codes available</div>
                                        @endforelse
                                    </div>
                                    <p class="px-4 py-3 text-xs select-none text-stone-500 dark:text-stone-400">
                                        You have {{ count($recoveryCodes) }} recovery codes left.
                                        Each can be used once to access your account and will be removed after use.
                                        If you need more, click <span class="font-bold">Regenerate Codes</span>
                                        above.
                                    </p>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="inline relative">
                        <flux:button
                            variant="danger"
                            icon="shield-ban"
                            wire:click="disable"
                            wire:loading.attr="disabled"
                            wire:target="disable"
                        >
                            <span wire:loading.remove wire:target="disable">{{ __('Disable 2FA') }}</span>
                            <span wire:loading wire:target="disable">{{ __('Disabling...') }}</span>
                        </flux:button>
                    </div>
                </div>
            @endif

            <flux:modal
                name="two-factor-modal"
                class="max-w-md"
                x-on:show-two-factor-modal.window="$flux.modal('two-factor-modal').show()"
                x-on:hide-two-factor-modal.window="$flux.modal('two-factor-modal').close()"
            >
                <div class="space-y-6">
                    <div class="flex flex-col items-center space-y-4">
                        <div
                            class="p-0.5 w-auto rounded-full border border-stone-100 dark:border-stone-600 bg-white dark:bg-stone-800 shadow-sm">
                            <div
                                class="p-2.5 rounded-full border border-stone-200 dark:border-stone-600 overflow-hidden bg-stone-100 dark:bg-stone-200 relative">
                                <div
                                    class="flex items-stretch absolute inset-0 w-full h-full divide-x [&>div]:flex-1 divide-stone-200 dark:divide-stone-300 justify-around opacity-50">
                                    @for($i = 1; $i <= 5; $i++)
                                        <div></div>
                                    @endfor
                                </div>
                                <div
                                    class="flex flex-col items-stretch absolute w-full h-full divide-y [&>div]:flex-1 inset-0 divide-stone-200 dark:divide-stone-300 justify-around opacity-50">
                                    @for($i = 1; $i <= 5; $i++)
                                        <div></div>
                                    @endfor
                                </div>
                                <flux:icon.scan-line class="size-6 relative z-20 dark:text-black"/>
                            </div>
                        </div>
                        <div class="text-center space-y-2">
                            <flux:heading size="lg">{{ $this->modalConfig['title'] }}</flux:heading>
                            <flux:text>{{ $this->modalConfig['description'] }}</flux:text>
                        </div>
                    </div>

                    @if(!$showVerificationStep)
                        <div class="space-y-6">
                            <div class="flex justify-center">
                                <div
                                    class="border border-stone-200 dark:border-stone-700 rounded-lg relative overflow-hidden w-64 aspect-square">
                                    @if(empty($qrCodeSvg))
                                        <div
                                            class="bg-white dark:bg-stone-700 animate-pulse flex items-center justify-center absolute inset-0">
                                            <flux:icon.loader-circle class="size-6 animate-spin"/>
                                        </div>
                                    @else
                                        <div class="p-4 flex items-center justify-center h-full">
                                            {!! $qrCodeSvg !!}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div>
                                <flux:button
                                    variant="primary"
                                    class="w-full"
                                    wire:click="proceedToVerification"
                                    :disabled="empty($qrCodeSvg)"
                                >
                                    {{ $this->modalConfig['buttonText'] }}
                                </flux:button>
                            </div>

                            <div class="space-y-4">
                                <div class="relative flex w-full items-center justify-center">
                                    <div
                                        class="absolute inset-0 top-1/2 h-px w-full bg-stone-200 dark:bg-stone-600"></div>
                                    <span
                                        class="relative bg-white dark:bg-stone-800 px-2 text-sm text-stone-600 dark:text-stone-400">
                                        or, enter the code manually
                                    </span>
                                </div>

                                <div class="flex items-center space-x-2"
                                     x-data="{ copied: false, copyToClipboard() {
                                         if (navigator && navigator.clipboard) {
                                             navigator.clipboard.writeText('{{ $manualSetupKey }}');
                                         }
                                         this.copied = true;
                                         setTimeout(() => this.copied = false, 1500);
                                     }}">
                                    <div
                                        class="w-full rounded-xl flex items-stretch border dark:border-stone-700 overflow-hidden">
                                        @if(empty($manualSetupKey))
                                            <div
                                                class="w-full flex items-center justify-center bg-stone-100 dark:bg-stone-700 p-3">
                                                <flux:icon.loader-circle class="size-4 animate-spin"/>
                                            </div>
                                        @else
                                            <input
                                                type="text"
                                                readonly
                                                value="{{ $manualSetupKey }}"
                                                class="w-full p-3 bg-transparent text-stone-900 dark:text-stone-100 outline-none"
                                            />
                                            <button
                                                x-on:click="copyToClipboard()"
                                                class="border-l border-stone-200 dark:border-stone-600 px-3 hover:bg-stone-100 dark:hover:bg-stone-700 transition-colors"
                                            >
                                                <flux:icon x-show="!copied" icon="copy" class="w-4"></flux:icon>
                                                <flux:icon x-show="copied" icon="check"
                                                           class="w-4 text-green-500"></flux:icon>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="space-y-6">
                            <div class="flex flex-col items-center space-y-3">
                                <x-input-otp
                                    :digits="6"
                                    name="authCode"
                                    wire:model="authCode"
                                    autocomplete="one-time-code"
                                />
                                @error('authCode')
                                <p class="text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex items-center space-x-3">
                                <flux:button
                                    variant="outline"
                                    class="flex-1"
                                    wire:click="backToSetup"
                                >
                                    {{ __('Back') }}
                                </flux:button>
                                <flux:button
                                    variant="primary"
                                    class="flex-1"
                                    wire:click="confirmTwoFactor"
                                    wire:loading.attr="disabled"
                                    wire:target="confirmTwoFactor"
                                >
                                    <span wire:loading.remove
                                          wire:target="confirmTwoFactor">{{ __('Confirm') }}</span>
                                    <span wire:loading
                                          wire:target="confirmTwoFactor">{{ __('Confirming...') }}</span>
                                </flux:button>
                            </div>
                        </div>
                    @endif
                </div>
            </flux:modal>
        </div>
    </x-settings.layout>
</section>
