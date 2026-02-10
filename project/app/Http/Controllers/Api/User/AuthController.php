<?php

namespace App\Http\Controllers\Api\User;
use App\Enums\DocumentType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Country;
use Illuminate\Http\Request;
use App\Models\Generalsetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\UserResource;

class AuthController extends ApiController
{
    /**
     * Register api
     *
     * @return \Illuminate\Http\Response
     */
    public function register(Request $request)
    {
        Log::info('inputdata',$request->all());
        $gs        = Generalsetting::first();
        $countries = Country::query();
        $name      = $countries->pluck('name')->toArray();

        if($gs->registration == 0){
            return $this->sendError('Registration Error',['Registration is currently off.' ]);
        }

        $validator = Validator::make($request->all(), [
            'first_name'                => 'required',
            'last_name'                 => 'required',
            'email'                     => ['required','email','unique:users',$gs->allowed_email != null ? 'email_domain:'.$request->email:''],
            'dial_code'                 => 'required',
            'phone'                     => 'required',
            'document_type'                     => 'required',
            'country'                   => 'required|in:'.implode(',',$name),
            'address'                   => 'required',
            'password'                  => 'required|min:6|confirmed',
        ],
            [
                'email.email_domain'          => 'Allowed emails are only within : '.$gs->allowed_email,
            ]);

        if($validator->fails()){
            return $this->sendError('Validation Error', $validator->errors());
        }
        // Format phone number using the same method as login
        $countryData         = $countries->where('name',$request->country)->first();
        $currencyId          = $countryData->currency_id;
        $country_code=$countryData->dial_code;
        $fullPhoneNumber = $this->formatPhoneNumber($country_code, $request->phone);
        Log::info('fullmobile '.$fullPhoneNumber);
        Log::info('fullmobile '.$fullPhoneNumber);

        // Check if phone number already exists
        if (User::where('phone', $fullPhoneNumber)->exists()) {
            return $this->sendError('Registration Error', ['Phone number is already registered.']);
        }

        $data = $request->only('first_name','last_name','email','dial_code','phone','country','address','password');

        $data['name'] = strtoupper($request->first_name . ' ' . $request->last_name);


        $data['phone']       = $fullPhoneNumber;
        $data['password']    = bcrypt($request->password);
        $data['email_verified'] = $gs->is_verify == 1 ? 0:1;

        // Create user
        $user = User::create($data);

        $success['token'] =  $user->createToken('wallet')->plainTextToken;
        $success['user']  =  new UserResource($user);

        // Create wallet with automatic account number generation
        // The Wallet model's boot() method will automatically:
        // 1. Generate account number (phone for KES, numeric for others)
        // 2. Set wallet_status to 'active'
        // 3. Set is_primary to true (since it's the only wallet)
        Wallet::create([
            'user_id'     => $user->id,
            'user_type'   => 1,
            'currency_id' => $currencyId,
            'balance'     => 0,
            'wallet_name' =>  $data['name'] // Optional: give it a default name
        ]);

        // Auto login
        Auth::guard('web')->attempt(['email' => $request->email, 'password' => $request->password]);

        // Send verification email if enabled
        if($gs->is_verify == 1){
            $user->verify_code = randNum();
            $user->save();

            @email([
                'email'   => $user->email,
                'name'    => $user->name,
                'subject' => __('Email Verification Code'),
                'message' => __('Email Verification Code is : '). $user->verify_code,
            ]);
        }

        return $this->sendResponse($success, 'User registered successfully.');
    }

    /**
     * Login api
     *
     * @return \Illuminate\Http\Response
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country_code' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors());
        }
        $loginField = filter_var($request->username, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        // Prepare the phone number if username is a phone
        if ($loginField === 'phone') {
            // Remove any non-numeric characters from phone number
            $phoneNumber = preg_replace('/[^0-9]/', '', $request->username);

            // Combine country code with phone number (remove leading zeros from phone)
            $phoneNumber = ltrim($phoneNumber, '0');
            $fullPhoneNumber = $this->formatPhoneNumber($request->country_code, $request->username);

            $credentials = [
                'phone' => $fullPhoneNumber,
                'password' => $request->password
            ];
        } else {
            // It's an email login
            $credentials = [
                'email' => $request->username,
                'password' => $request->password
            ];
        }

        // Attempt login
        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            // Get the general settings
            $gs = Generalsetting::first();

            $success['token'] = $user->createToken('wallet')->plainTextToken;
            $success['user'] = new UserResource($user);

            // Generate and store 2FA code if 2FA is enabled for the user
            if ($gs->two_fa && $user->two_fa_status == 1) {
                $code = randNum();
                $user->two_fa_code = $code;
                $user->save();

                // Optional: Send 2FA code via SMS
                // sendSMS($user->phone, 'Your OTP code is: ' . $code, $gs->contact_no);

                $success['requires_2fa'] = true;
                $success['message'] = 'Login successful. 2FA verification required.';
            } else {
                $success['requires_2fa'] = false;
                $success['message'] = 'Login successful.';
            }

            return $this->sendResponse($success, $success['message']);
        } else {
            return $this->sendError('Authentication Error', ['Invalid credentials provided.'], 401);
        }

        if(Auth::attempt(['email' => $request->email, 'password' => $request->password])){
            $user = Auth::user();
            $success['token'] =  $user->createToken('wallet')->plainTextToken;
            $success['user']  =  new UserResource($user);

            $code = randNum();
            $user->two_fa_code = $code;
            $user->update();
            return $this->sendResponse($success, 'Login successful.');
        }
        else{
            return $this->sendError('Error.', ['Unauthorised access']);
        }
    }

    public function logout(Request $request)
    {
        if(!$request->user()){
            return $this->sendError('Error.', ['Unauthorised access']);
        }
        $request->user()->currentAccessToken()->delete();
        if($request->user()->two_fa_status == 1){
            $request->user()->two_fa = 1;
            $request->user()->save();
        }
        Auth::logout();
        return $this->sendResponse(['success'], 'Logout successful.');
    }

    public function forgotPasswordSubmit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);
        if($validator->fails()){
            return $this->sendError('Validation Error', $validator->errors());
        }

        $exist = User::where('email',$request->email)->first();
        if(!$exist){
            return $this->sendError('error',['Sorry! Email doesn\'t exist']);
        }

        $exist->verify_code = randNum();
        $exist->save();

        @email([
            'email'   => $exist->email,
            'name'    => $exist->name,
            'subject' => __('Password Reset Code'),
            'message' => __('Password reset code is : ').$exist->verify_code,
        ]);

        $success['email'] = $exist->email;
        $success['verify_code'] = $exist->verify_code;
        return $this->sendResponse($success,'Reset code has been sent to email.');
    }

    public function verifyCodeSubmit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|integer',
            'email'=> 'required|email'
        ]);

        if($validator->fails()){
            return $this->sendError('Validation Error', $validator->errors());
        }

        $user = User::where('email',$request->email)->first();
        if(!$user){
            return $this->sendError('error',['User doesn\'t exist']);
        }

        if($user->verify_code != $request->code){
            return $this->sendError('error',['Invalid verification code.']);
        }
        $success['code'] = $request->code;
        $success['email'] = $request->email;
        return $this->sendResponse($success,'Reset code has been verified');
    }

    public function resetPasswordSubmit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'=>'required|email',
            'code'=>'required|integer',
            'password'=>'required|confirmed|min:6'
        ]);

        if($validator->fails()){
            return $this->sendError('Validation Error', $validator->errors());
        }
        $user = User::where('email',$request->email)->first();
        if(!$user || !$request->code){
            return $this->sendError('Error', ['Invalid request']);
        }
        $user->password = bcrypt($request->password);
        $user->update();
        return $this->sendResponse(['success'],'Password reset successful.');
    }

    public function twoStepVerify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code'=>'required|integer',
        ]);

        if($validator->fails()){
            return $this->sendError('Validation Error', $validator->errors());
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
        $user->save();
        return $this->sendResponse(['success'],$msg);
    }



    public function twoStepCodeVerify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code'=>'required|integer',
        ]);

        if($validator->fails()){
            return $this->sendError('Validation Error', $validator->errors());
        }
        $user = auth()->user();
        if($request->code != $user->two_fa_code){
            return $this->sendError('Error',['Invalid OTP']);
        }

        return $this->sendResponse(['success'],'Valid OTP');
    }

    public function twoStepResendCode()
    {
        $user = auth()->user();
        $code = randNum();
        $user->two_fa_code = $code;
        $user->update();
        sendSMS($user->phone,'Your two step authentication OTP is : '.$code,Generalsetting::value('contact_no'));
        return $this->sendResponse(['success'=>true,'code'=>$code],'OTP code is sent to your phone.');
    }


    public function twoStepsendCode(Request $request)
    {
        $validator = Validator::make($request->all(), ['password'=>'required|confirmed']);

        if($validator->fails()){
            return $this->sendError('Validation Error', $validator->errors());
        }

        $code = randNum();
        $user = auth()->user();
        $user->two_fa_code = $code;
        $user->update();
        sendSMS($user->phone,'Your two step authentication OTP is : '.$code,Generalsetting::value('contact_no'));
        return $this->sendResponse(['success'=>true,'code'=>$code],'OTP code is sent to your phone.');
    }


    public function verifyEmailSubmit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code'=>'required|integer',
        ]);

        if($validator->fails()){
            return $this->sendError('Validation Error', $validator->errors());
        }
        $user = auth()->user();
        if(!$user){
            return $this->sendError('Error',['User doesn\'t exist']);
        }

        if($user->verify_code != $request->code){
            return $this->sendError('Error',['Invalid verification code.']);
        }

        $user->verify_code = null;
        $user->email_verified = 1;
        $user->save();

        return $this->sendResponse(['success'],'Email has been verified');

    }

    public function verifyEmailResendCode()
    {
        $code = randNum();
        $user = auth()->user();
        $user->verify_code = $code;
        $user->update();

        @email([
            'email'   => $user->email,
            'name'    => $user->name,
            'subject' => __('Email Verification Code'),
            'message' => __('Email Verification Code is : '). $user->verify_code,
        ]);

        return $this->sendResponse(['success'],'Verify code resent to your email.');
    }


    public function settings(){
        $main = Generalsetting::findOrFail(1);
        $setting['logo'] = asset('assets/images/'.$main->logo);
        $setting['title'] = $main->title;
        $setting['is_maintenance'] = $main->is_maintenance;
        $setting['two_fa'] = $main->two_fa;
        return $this->sendResponse($setting,'Setting Data');
    }
//    public function documentType()
//    {
//        $data['document_types'] = collect(DocumentType::cases())
//            ->map(fn($type) => $type->value)
//            ->values();
//        return $this->sendResponse($data,'Document Type');
//    }
    public function documentType()
    {
        $data['document_types'] = collect(DocumentType::cases())
            ->map(fn($type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ])
            ->values();

        return $this->sendResponse($data, 'Document Type');
    }
    private function formatPhoneNumber($countryCode, $phoneNumber)
    {
        // Remove all non-numeric characters
        $countryCode = preg_replace('/[^0-9]/', '', $countryCode);
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Remove country code prefix if it already exists in phone number
        // If phone number starts with the country code, remove it
        if (str_starts_with($phoneNumber, $countryCode)) {
            $phoneNumber = substr($phoneNumber, strlen($countryCode));
        }

        // Remove leading zero(s) from phone number
        $phoneNumber = ltrim($phoneNumber, '0');

        // Ensure country code doesn't have leading plus or zeros
        $countryCode = ltrim($countryCode, '0');

        // Return formatted phone number without plus sign
        return $countryCode . $phoneNumber;
    }

}
