<?php

namespace App\Http\Controllers\Api\User;


use Illuminate\Support\Facades\Log;
use Image;
use App\Models\Wallet;
use App\Models\Deposit;
use App\Models\KycForm;
use App\Models\Transaction;
use App\Models\Withdrawals;
use App\Helpers\MediaHelper;
use Illuminate\Http\Request;
use App\Models\Generalsetting;
use Illuminate\Support\Facades\Hash;
use App\Http\Resources\User\UserResource;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\UserResource as User;
use App\Services\Choice\ChoiceOnboardingService;
class UserController extends ApiController{

    public function __construct(
        UserResource $resource,
        private readonly ChoiceOnboardingService $choiceOnboarding,
    ) {
        $this->resource = $resource;
    }


    public function trnx()
    {
       return Transaction::where('user_id',auth()->id())->where('user_type',1)->with('currency');
    }

    public function userInfo(){
        $success['user'] = new User(auth()->user());
        return $this->sendResponse($success,'success');

    }

    public function index()
    {
        $success['wallets'] = Wallet::where('user_id',auth()->id())->where('user_type',1)->with('currency')->get();
        $success['transactions'] = $this->trnx()->latest()->take(8)->get();

        $amount = collect([]);
        $this->trnx()->where('remark','transfer_money')->get()->map(function($q) use($amount){
            $amount->push((float) amountConv($q->amount,$q->currency));
        });
        $success['totalTransferMoney'] = $amount->sum();

        $exchange = collect([]);
        $this->trnx()->where('remark','money_exchange')->get()->map(function($q) use($exchange){
            $exchange->push((float) amountConv($q->amount,$q->currency));
        });
        $success['totalExchange'] = $exchange->sum();

        $deposit = collect([]);
        Deposit::where('user_id',auth()->id())->with('currency')->get()->map(function($q) use($deposit){
            $deposit->push((float) amountConv($q->amount,$q->currency));
        });
        $success['totalDeposit'] = $deposit->sum();

        $withdraw = collect([]);
        Withdrawals::where('user_id',auth()->id())->with('currency')->get()->map(function($q) use($withdraw){
            $withdraw->push((float) amountConv($q->amount,$q->currency));
        });
        $success['totalWithdraw'] = $withdraw->sum();

        return $this->sendResponse($success,'success');
    }



    public function profileSubmit(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'name' => 'required',
            'phone' => 'required',
            'photo' => 'image|mimes:jpg,jpeg,png',
            'city' => 'required',
            'zip' => 'required',
        ]);

        if($validator->fails()){
            return $this->sendError('Validation Error',$validator->errors());
        }

        $user          = auth()->user();
        $user->name    = $request->name;
        $user->phone   = $request->phone;
        $user->city    = $request->city;
        $user->zip     = $request->zip;
        $user->address = $request->address;

        if($request->photo){
            $user->photo = MediaHelper::handleMakeImage($request->photo,[300,300]);
        }

        $user->update();
        $user['photo'] = asset('assets/images/'.$user->photo);
        return $this->sendResponse($user,'Profile has been updated');
    }

    public function changePass(Request $request)
    {
        $validator = Validator::make($request->all(),['old_pass'=>'required','password'=>'required|min:6|confirmed']);
        if($validator->fails()){
            return $this->sendError('Validation Error',$validator->errors());
        }
        $user = auth()->user();
        if (Hash::check($request->old_pass, $user->password)) {
            $password = bcrypt($request->password);
            $user->password = $password;
            $user->save();
            return $this->sendResponse(['success'], 'Password has been changed');
        } else {
            return $this->sendError('Error', ['The old password doesn\'t match!']);
        }
    }



    public function kycForm()
    {
        if(auth()->user()->kyc_status == 2) return $this->sendError('Error',['You have already submitted the KYC data.']);
        if(auth()->user()->kyc_status == 1) return $this->sendError('Error',['Your KYC data is already verified.']);
        //$success['kyc_form_data'] = KycForm::where('user_type',1)->get();
        $schema = $this->getKycSchema();
        if (empty($schema)) {
            return $this->sendError('Error', ['KYC schema not configured for this user type.']);
        }

        $success['kyc_form_data'] = $schema;
        $success['user_type'] = auth()->user()->user_type;

        return $this->sendResponse($success,'success');
    }

    public function kycFormSubmit(Request $request)
    {
        $user = auth()->user();

        if ($user->kyc_status == 2) return $this->sendError('Error', ['You have already submitted the KYC data.']);
        if ($user->kyc_status == 1) return $this->sendError('Error', ['Your KYC data is already verified.']);

        $schema = $this->getKycSchema();

        if (!is_array($schema) || count($schema) === 0) {
            return $this->sendError('KYC Configuration Error', ['KYC form is not available for this account type.']);
        }

        $rules = [];
        $data  = ['details' => [], 'image' => []];

        foreach ($schema as $field) {
            $key      = $field['key'] ?? null;
            $type     = strtolower((string) ($field['type'] ?? 'text'));
            $required = (bool) ($field['required'] ?? false);

            if (!$key) continue;

            $r = ['bail', $required ? 'required' : 'nullable'];

            if ($type === 'image') {
                $r[] = 'image';
                // Choice supports jpg/jpeg only
                $r[] = 'mimes:jpg,jpeg';
                $r[] = 'max:5120';
            } elseif ($type === 'date') {
                $r[] = 'date';
            } elseif ($type === 'select') {
                $values = collect($field['options'] ?? [])->pluck('value')->toArray();
                if (!empty($values)) $r[] = 'in:' . implode(',', $values);
                $r[] = 'string';
            } else {
                $r[] = 'string';
            }

            $rules[$key] = implode('|', $r);
        }

        // ---- Conditional requirements for individual docs (Choice rules) ----
        if ((int) $user->user_type === 1) {
            $idType = (string) $request->input('id_type'); // 101/102/103

            // selfie always required
            $rules['selfie'] = 'bail|required|image|mimes:jpg,jpeg|max:5120';

            if (in_array($idType, ['101', '102'], true)) {
                // National ID / Alien ID require front + back
                $rules['id_front'] = 'bail|required|image|mimes:jpg,jpeg|max:5120';
                $rules['id_back']  = 'bail|required|image|mimes:jpg,jpeg|max:5120';
            } elseif ($idType === '103') {
                // Passport requires only one photo (we use id_front as passport photo)
                $rules['id_front'] = 'bail|required|image|mimes:jpg,jpeg|max:5120';
                $rules['id_back']  = 'bail|nullable|image|mimes:jpg,jpeg|max:5120';
            }
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors());
        }

        foreach ($schema as $field) {
            $key  = $field['key'] ?? null;
            $type = strtolower((string) ($field['type'] ?? 'text'));
            if (!$key) continue;

            if ($type === 'image') {
                if ($request->hasFile($key)) {
                    $filename = MediaHelper::handleMakeImage($request->file($key));
                    $data['image'][$key] = $filename;
                }
            } else {
                $val = $request->input($key);
                if ($val !== null && $val !== '') {
                    $data['details'][$key] = $val;
                }
            }
        }

        // optional metadata
        $data['_meta'] = [
            'user_type' => (int) $user->user_type,
            'form_type' => ((int) $user->user_type === 1) ? 'individual' : 'company',
            'submitted_at' => now()->toISOString(),
        ];

        $user->kyc_info = $data;
        $user->kyc_status = 2; // pending/submitted
        $user->save();

        // ---- Choice onboarding (individual only for now) ----
        if ((int) $user->user_type === 1) {
            try {
                $details = $data['details'] ?? [];
                $images  = $data['image'] ?? [];

                $idType = (string) ($details['id_type'] ?? '');
                $gender = isset($details['gender']) ? (int) $details['gender'] : null;

                if (!in_array($idType, ['101', '102', '103'], true)) {
                    throw new \RuntimeException('Invalid idType for Choice. Must be 101/102/103.');
                }
                if (!in_array($gender, [0, 1], true)) {
                    throw new \RuntimeException('Invalid gender for Choice. Must be 0 (Female) or 1 (Male).');
                }


                $phone = $this->parsePhoneForChoice((string) $user->phone, $user->country);

                $payload = [
                    'userId'           => (string) $user->id,
                    'firstName'        => (string) ($details['first_name'] ?? ''),
                    'middleName'       => (string) ($details['middle_name'] ?? ''),
                    'lastName'         => (string) ($details['last_name'] ?? ''),
                    'birthday'         => (string) ($details['date_of_birth'] ?? ''), // yyyy-MM-dd
                    'address'          => (string) ($user->address ?? ''),
                    'gender'           => $gender, // 0/1
                    'countryCode'      => (string) $phone['countryCode'],
                    'mobile'           => (string) $phone['mobile'],
                    'email'            => (string) ($user->email ?? ''),
                    'idType'           => $idType,
                    'idNumber'         => (string) ($details['id_number'] ?? ''),
                    'kraPin'           => (string) ($details['kra_pin'] ?? ''),
                    'employmentStatus' => (string) ($details['employment_status'] ?? ''),
                    'monthlyIncome'    => (string) ($details['monthly_income'] ?? ''),
                ];
                Log::info('Choice phone computed', [
                    'db_phone' => $user->phone,
                    'country' => $user->country,
                    'countryCode' => $phone['countryCode'],
                    'mobile' => $phone['mobile'],
                ]);
                Log::info('payload  is',['data'=>$payload]);

                $choiceResp = $this->choiceOnboarding->submitOnboarding($payload);
                Log::info('response is',['response' => $choiceResp]);

                $onboardingRequestId =
                    data_get($choiceResp, 'onboardingRequestId')
                    ?? data_get($choiceResp, 'data.onboardingRequestId')
                    ?? null;

                if (!$onboardingRequestId) {
                    throw new \RuntimeException('Choice did not return onboardingRequestId.');
                }

                $uploadResults = [];

                foreach ($images as $key => $filename) {
                    $mediaType = $this->choiceMediaTypeForKey($key, $idType);
                    if (!$mediaType) {
                        $uploadResults[$key] = ['status' => 'SKIPPED', 'reason' => 'no_media_mapping'];
                        continue;
                    }

                    $path = public_path('assets/images/' . $filename);
                    if (!file_exists($path)) {
                        $uploadResults[$key] = ['status' => 'SKIPPED', 'reason' => 'file_missing', 'filename' => $filename];
                        continue;
                    }

                    $base64 = base64_encode(file_get_contents($path));

                    $uploadResults[$key] = $this->choiceOnboarding->uploadMedia(
                        $onboardingRequestId,
                        $mediaType,
                        $base64
                    );
                }

                $kyc = $user->kyc_info ?? [];
                $kyc['choice'] = [
                    'status' => 'SUBMITTED',
                    'onboardingRequestId' => $onboardingRequestId,
                    'uploaded' => $uploadResults,
                    'updated_at' => now()->toISOString(),
                ];
                $user->kyc_info = $kyc;
                $user->save();

            } catch (\Throwable $e) {
                \Log::error('Choice onboarding failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                $kyc = $user->kyc_info ?? [];
                $kyc['choice'] = [
                    'status' => 'FAILED',
                    'error' => $e->getMessage(),
                    'updated_at' => now()->toISOString(),
                ];
                $user->kyc_info = $kyc;
                $user->save();
            }
        }

        return $this->sendResponse(['success' => true], 'KYC data has been submitted for review.');
    }

    public function generateQR()
    {
        return $this->sendResponse(['qrcode_image' =>  generateQR(auth()->user()->email)],'QR code has been generated');
    }


    public function transactions()
    {
        $remark = request('remark');
        $search = request('search');

        $success['transactions'] = Transaction::where('user_id',auth()->id())->where('user_type',1)
        ->when($remark,function($q) use($remark){
            return $q->where('remark',$remark);
        })
        ->when($search,function($q) use($search){
            return $q->where('trnx',$search);
        })
        ->with('currency')->latest()->paginate(15);

        $success['remark_list'] = [
            'transfer_money','request_money','money_exchange','invoice_payment','merchant_payment','merchant_api_payment','escrow_return','make_escrow','withdraw_money','withdraw_reject','redeem_voucher',
            'create_voucher','deposit','cash_out'
        ];
        $success['remark'] = $remark;
        $success['search'] = $search;

        return $this->sendResponse($success,'Transaction history');

    }

    public function trxDetails($id)
    {
        $success['transaction'] = Transaction::where('id',$id)->where('user_type',1)->where('user_id',auth()->id())->first();
        if(!$success['transaction']){
            return $this->sendError('Error',['Transaction not found']);
        }
        return $this->sendResponse($success,'Transaction details');
    }



    public function twoStepSendCode(Request $request)
    {
        $validator = Validator::make($request->all(),['password'=>'required|confirmed']);
        if( $validator->fails()){
            return $this->sendError('Validation Error',$validator->errors());
        }

        $user = auth()->user();
        if (Hash::check($request->password, $user->password)) {
            $code = randNum();
            $user->two_fa_code = $code;
            $user->update();
            sendSMS($user->phone,trans('Your two step authentication OTP is : ').$code,Generalsetting::value('contact_no'));
            return $this->sendResponse(['success'=>true,'code'=>$code],'OTP code is sent to your phone.');
        } else {
            return $this->sendError('Error', ['The password doesn\'t match!']);
        }

    }


    public function twoStepVerifySubmit(Request $request)
    {
        $validator = Validator::make($request->all(),['code'=>'required']);
        if( $validator->fails()){
            return $this->sendError('Validation Error',$validator->errors());
        }

        $user = auth()->user();
        if($request->code != $user->two_fa_code){
            return $this->sendError('Error',['Invalid OTP']);
        }
        if($user->two_fa_status == 1){
            $user->two_fa_status = 0;
            $user->two_fa= 0;
            $msg = 'Your two step authentication is de-activated';
        }else{
            $user->two_fa_status = 1;
            $msg = 'Your two step authentication is activated';
        }
        $user->two_fa_code = null;
        $user->save();
        return $this->sendResponse(['success'],$msg);
    }
    private function getKycSchema(): array
    {
        $user = auth()->user();

        return match ((int) $user->user_type) {
            1 => config('kyc.individual'),
            2 => config('kyc.company'),
            default => [],
        };
    }
    private function choiceMediaTypeForKey(string $key, string $idType): ?string
    {
        // Documents table:
        // KYCF00001 National ID front (idType 101)
        // KYCF00002 National ID back  (idType 101)
        // KYCF00003 Passport photo    (idType 103)
        // KYCF00004 Alien ID front    (idType 102)
        // KYCF00005 Alien ID back     (idType 102)
        // KYCF00006 Selfie            (always)

        if ($key === 'selfie') return 'KYCF00006';

        if ($idType === '101') {
            return match ($key) {
                'id_front' => 'KYCF00001',
                'id_back'  => 'KYCF00002',
                default    => null,
            };
        }

        if ($idType === '102') {
            return match ($key) {
                'id_front' => 'KYCF00004',
                'id_back'  => 'KYCF00005',
                default    => null,
            };
        }

        if ($idType === '103') {
            // Use id_front as passport photo
            return $key === 'id_front' ? 'KYCF00003' : null;
        }

        return null;
    }

    private function parsePhoneForChoice(string $phone, ?string $countryName = null): array
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $map = [
            'Kenya' => '254',
            'Uganda' => '256',
            'Tanzania' => '255',
            'Rwanda' => '250',
            'Bangladesh' => '880',
        ];

        $cc = ($countryName && isset($map[$countryName])) ? $map[$countryName] : '';

        // If already contains country code, remove it
        if ($cc !== '' && str_starts_with($digits, $cc)) {
            $local = substr($digits, strlen($cc));
        } else {
            // If kenya-like number begins with 254 but country name missing
            if (str_starts_with($digits, '254')) {
                $cc = '254';
                $local = substr($digits, 3);
            } else {
                $local = $digits;
            }
        }

        // Remove leading zeros (0701.. -> 701..)
        $local = ltrim($local, '0');

        // Kenya: ensure we send 9-digit mobile (e.g. 701209288)
        if ($cc === '254') {
            // if someone passed 10 digits like 7012092880 or other, keep last 9 as fallback
            if (strlen($local) > 9) $local = substr($local, -9);
        }

        return ['countryCode' => $cc, 'mobile' => $local];
    }
}
