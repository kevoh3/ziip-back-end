<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_type',
        'currency_id',
        'balance',
        'wallet_name',
        'description',
        'wallet_internal_account_number',
        'wallet_external_provider',
        'wallet_external_provider_number',
        'provider_wallet_type',
        'provider_reference_id',
        'provider_metadata',
        'wallet_status',
        'is_primary'
    ];

    protected $casts = [
        'provider_metadata' => 'array',
        'balance' => 'decimal:10',
        'is_primary' => 'boolean'
    ];

    protected $with = ['currency'];
    protected $appends = ['display_name', 'formatted_account_number'];

    // Constants for account number generation
    const STARTING_ACCOUNT_NUMBER = 5001;

    // Countries that should use phone numbers as account numbers
    const PHONE_LENGTHS_BY_COUNTRY = [
        'KE' => 12, // Kenya: 254XXXXXXXXX
        'SO' => 12, // Somalia: 252XXXXXXXXX
        'ET' => 12, // Ethiopia: 251XXXXXXXXX
        'TZ' => 12, // Tanzania: 255XXXXXXXXX
        'UG' => 12, // Uganda: 256XXXXXXXXX
        'RW' => 12, // Rwanda: 250XXXXXXXXX
        'BI' => 12, // Burundi: 257XXXXXXXXX
        'SS' => 12, // South Sudan: 211XXXXXXXXX
        'NG' => 13, // Nigeria: 234XXXXXXXXXXX
        'GH' => 12, // Ghana: 233XXXXXXXXX
        'ZA' => 11, // South Africa: 27XXXXXXXXX
        // Add more as needed
    ];

    // Accessor for display name
    public function getDisplayNameAttribute()
    {
        if ($this->wallet_name) {
            return $this->wallet_name;
        }

        $currency = $this->currency->code ?? 'Unknown';
        $provider = $this->wallet_external_provider ? " ({$this->wallet_external_provider})" : '';

        return "{$currency} Wallet{$provider}";
    }

    // Accessor for formatted account number WITHOUT + sign
    public function getFormattedAccountNumberAttribute()
    {
        if ($this->isPhoneNumberAccount()) {
            $phone = $this->wallet_internal_account_number;
            $countryInfo = $this->getCountryFromPhone($phone);

            if ($countryInfo) {
                $dialCode = $countryInfo->dial_code;
                $countryCode = $countryInfo->code;
                $phoneWithoutCode = substr($phone, strlen(preg_replace('/[^0-9]/', '', $dialCode)));

                // Format based on country code (WITHOUT + sign)
                switch ($countryCode) {
                    case 'KE': // Kenya: 254 748 299 322
                        return '254 ' . substr($phone, 3, 3) . ' ' . substr($phone, 6, 3) . ' ' . substr($phone, 9);
                    case 'SO': // Somalia: 252 61 1234567
                        return '252 ' . substr($phone, 3, 4) . ' ' . substr($phone, 7, 5);
                    case 'ET': // Ethiopia: 251 91 123 4567
                        return '251 ' . substr($phone, 3, 2) . ' ' . substr($phone, 5, 3) . ' ' . substr($phone, 8, 4);
                    case 'NG': // Nigeria: 234 123 456 7890
                        return '234 ' . substr($phone, 3, 3) . ' ' . substr($phone, 6, 3) . ' ' . substr($phone, 9, 4);
                    case 'US': // USA: 1 555 123 4567
                        return '1 ' . substr($phone, 1, 3) . ' ' . substr($phone, 4, 3) . ' ' . substr($phone, 7, 4);
                    default:
                        // Simple format: dial_code space rest_of_number
                        $cleanDialCode = preg_replace('/[^0-9]/', '', $dialCode);
                        return $cleanDialCode . ' ' . $phoneWithoutCode;
                }
            }
        }

        // For numeric accounts, return as is
        return $this->wallet_internal_account_number;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    protected static function boot()
    {
        parent::boot();

        // Auto-generate internal account number and set defaults
        static::creating(function ($wallet) {
            // Generate account number if not provided
            if (!$wallet->wallet_internal_account_number) {
                $wallet->wallet_internal_account_number = self::generateAccountNumber($wallet);
            }

            // Set default wallet status
            if (!$wallet->wallet_status) {
                $wallet->wallet_status = 'active';
            }

            // Set as primary if not specified (each user has only one wallet)
            if ($wallet->is_primary === null) {
                $wallet->is_primary = true;
            }
        });

        // Ensure only one primary wallet per user per currency
        static::updated(function ($wallet) {
            if ($wallet->isDirty('is_primary') && $wallet->is_primary) {
                self::where('user_id', $wallet->user_id)
                    ->where('user_type', $wallet->user_type)
                    ->where('currency_id', $wallet->currency_id)
                    ->where('id', '!=', $wallet->id)
                    ->update(['is_primary' => false]);
            }
        });
    }

    /**
     * Generate account number based on user's country and currency
     */
    public static function generateAccountNumber($wallet)
    {
        // Get the user
        $user = User::find($wallet->user_id);
        if (!$user) {
            return self::generateSimpleNumericNumber();
        }

        // Get user's country from database
        $country = Country::where('name', $user->country)->first();
        if (!$country) {
            return self::generateSimpleNumericNumber();
        }

        // Get the currency
        $currency = Currency::find($wallet->currency_id);
        if (!$currency) {
            return self::generateSimpleNumericNumber();
        }

        // Check if user's country currency matches wallet currency
        if ($country->currency_id != $currency->id) {
            // This shouldn't happen in normal flow, but just in case
            return self::generateSimpleNumericNumber();
        }

        // Check if user has a phone number
        if (empty($user->phone)) {
            return self::generateSimpleNumericNumber();
        }

        // Format the phone number for this country
        $phoneNumber = self::formatPhoneForCountry($user->phone, $country);

        if ($phoneNumber && self::isValidPhoneForCountry($phoneNumber, $country)) {
            // Check if this phone number is already used as account number
            $exists = self::where('wallet_internal_account_number', $phoneNumber)->exists();

            if (!$exists) {
                return $phoneNumber;
            }
        }

        // For all other cases, generate simple numeric number
        return self::generateSimpleNumericNumber();
    }

    /**
     * Format phone number for a specific country
     */
    private static function formatPhoneForCountry($phone, $country)
    {
        if (empty($phone) || empty($country->dial_code)) {
            return null;
        }

        // Clean the phone number (remove +, spaces, etc.)
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

        // Remove leading 0 if present
        $cleanPhone = ltrim($cleanPhone, '0');

        $dialCode = preg_replace('/[^0-9]/', '', $country->dial_code);

        // If phone doesn't start with country code, add it
        if (!str_starts_with($cleanPhone, $dialCode)) {
            // Get expected phone length for this country
            $expectedLength = self::getExpectedPhoneLength($country->code);
            $expectedLocalLength = $expectedLength - strlen($dialCode);

            // Check if it's the right length without country code
            if (strlen($cleanPhone) === $expectedLocalLength) {
                $cleanPhone = $dialCode . $cleanPhone;
            } else {
                // Try to see if it might be a valid number
                return $cleanPhone;
            }
        }

        return $cleanPhone;
    }

    /**
     * Get expected phone length for a country
     */
    private static function getExpectedPhoneLength($countryCode)
    {
        return self::PHONE_LENGTHS_BY_COUNTRY[$countryCode] ?? 12; // Default to 12
    }

    /**
     * Validate phone number for a specific country
     */
    private static function isValidPhoneForCountry($phone, $country)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $dialCode = preg_replace('/[^0-9]/', '', $country->dial_code);

        // Check starts with correct dial code
        if (!str_starts_with($phone, $dialCode)) {
            return false;
        }

        // Check length
        $expectedLength = self::getExpectedPhoneLength($country->code);
        if (strlen($phone) !== $expectedLength) {
            return false;
        }

        // Check remaining digits are all numbers
        $remaining = substr($phone, strlen($dialCode));
        return ctype_digit($remaining);
    }

    /**
     * Get country from phone number
     */
    private function getCountryFromPhone($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Check all countries in database
        $countries = Country::all();

        foreach ($countries as $country) {
            $dialCode = preg_replace('/[^0-9]/', '', $country->dial_code);

            if (str_starts_with($phone, $dialCode)) {
                $expectedLength = self::getExpectedPhoneLength($country->code);
                if (strlen($phone) === $expectedLength) {
                    return $country;
                }
            }
        }

        return null;
    }

    /**
     * Generate simple numeric account number starting from 5001
     */
    private static function generateSimpleNumericNumber()
    {
        // Get the highest numeric account number that's not a phone number
        $highestNumber = self::whereRaw('LENGTH(wallet_internal_account_number) <= 10') // Phone numbers are usually 12+ digits
        ->whereRaw('wallet_internal_account_number REGEXP \'^[0-9]+$\'') // Only digits
        ->max(DB::raw('CAST(wallet_internal_account_number AS UNSIGNED)'));

        // Start from 5001 or next number after highest
        $nextNumber = max(($highestNumber ?: (self::STARTING_ACCOUNT_NUMBER - 1)) + 1, self::STARTING_ACCOUNT_NUMBER);

        return (string) $nextNumber;
    }

    /**
     * Check if account number is a valid phone number for any country
     */
    public function isPhoneNumberAccount()
    {
        if (!is_numeric($this->wallet_internal_account_number)) {
            return false;
        }

        $number = $this->wallet_internal_account_number;

        // Check against all countries in database
        $countries = Country::all();

        foreach ($countries as $country) {
            $dialCode = preg_replace('/[^0-9]/', '', $country->dial_code);
            $expectedLength = self::getExpectedPhoneLength($country->code);

            if (strlen($number) === $expectedLength && str_starts_with($number, $dialCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get which country this phone account belongs to
     */
    public function getPhoneAccountCountry()
    {
        if (!$this->isPhoneNumberAccount()) {
            return null;
        }

        return $this->getCountryFromPhone($this->wallet_internal_account_number);
    }

    /**
     * Check if account number is simple numeric (not phone)
     */
    public function isSimpleNumericAccount()
    {
        return is_numeric($this->wallet_internal_account_number) &&
            !$this->isPhoneNumberAccount();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('wallet_status', 'active');
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeByProvider($query, $provider)
    {
        return $query->where('wallet_external_provider', $provider);
    }

    public function scopeByCurrencyCode($query, $currencyCode)
    {
        return $query->whereHas('currency', function ($q) use ($currencyCode) {
            $q->where('code', $currencyCode);
        });
    }

    public function scopeByCountryCode($query, $countryCode)
    {
        return $query->whereHas('user', function ($q) use ($countryCode) {
            $q->whereHas('countryRelation', function ($q2) use ($countryCode) {
                $q2->where('code', $countryCode);
            });
        });
    }

    /**
     * Get the primary wallet for a user (each user has only one)
     */
    public static function getPrimaryWallet($userId, $userType, $currencyId = null)
    {
        $query = self::where('user_id', $userId)
            ->where('user_type', $userType)
            ->where('is_primary', true);

        if ($currencyId) {
            $query->where('currency_id', $currencyId);
        }

        return $query->first();
    }

    /**
     * Get user's wallet (simplified - each user has only one)
     */
    public static function getUserWallet($userId, $userType = 1)
    {
        return self::where('user_id', $userId)
            ->where('user_type', $userType)
            ->first();
    }

    /**
     * Check if wallet is external provider wallet
     */
    public function isExternalProvider()
    {
        return !empty($this->wallet_external_provider) && !empty($this->wallet_external_provider_number);
    }

    /**
     * Get provider-specific metadata
     */
    public function getProviderMetadata($key = null, $default = null)
    {
        $metadata = $this->provider_metadata ?? [];

        if ($key === null) {
            return $metadata;
        }

        return data_get($metadata, $key, $default);
    }

    /**
     * Set provider-specific metadata
     */
    public function setProviderMetadata($key, $value = null)
    {
        $metadata = $this->provider_metadata ?? [];

        if (is_array($key)) {
            $metadata = array_merge($metadata, $key);
        } else {
            data_set($metadata, $key, $value);
        }

        $this->provider_metadata = $metadata;
        return $this;
    }
}
