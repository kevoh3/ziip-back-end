<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Country;
use App\Models\Deposit;
use App\Models\LoginLogs;
use App\Models\Transaction;
use App\Models\Withdrawals;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Generalsetting;
use Illuminate\Support\Facades\Auth;

class ManageUserController extends Controller
{

    public function index()
    {
        $search = request('search');
        $status = null;
        if (request('status') == 'active')  $status = 1;
        if (request('status') == 'banned')  $status = 2;

        $users = User::when($status, function ($q) use ($status) {
            return $q->where('status', $status);
        })->when($search, function ($q) use ($search) {
            return $q->where('email', 'like', "%$search%");
        })->latest()->paginate(15);

        return view('admin.user.index', compact('users', 'search'));
    }


    public function create()
    {
        $countries = Country::get(['id', 'name', 'dial_code']);
        return view('admin.user.create', compact('countries'));
    }


    public function store(Request $request)
    {
        $gs = Generalsetting::first();
        if ($gs->registration == 0) {
            return back()->with('error', 'Registration is currently off.');
        }

        $countries = Country::query();
        $name = $countries->pluck('name')->toArray();
        $data = $request->validate(
            [
                'name' => 'required',
                'email' => ['required', 'email', 'unique:users', $gs->allowed_email != null ? 'email_domain:' . $request->email : ''],
                'phone' => 'required',
                'country' => 'required|in:' . implode(',', $name),
                'address' => 'required',
                'password' => 'required|min:6|confirmed',
            ]

        );

        $currency = $countries->where('name', $request->country)->value('currency_id');
        $data['phone'] = $request->dial_code . $request->phone;
        $data['password'] = bcrypt($request->password);
        $data['email_verified'] = $gs->is_verify == 1;
        $user = User::create($data);

        Wallet::create([
            'user_id' => $user->id,
            'user_type' => 1,
            'currency_id' => $currency,
            'balance' => 0
        ]);

    
        return back()->with('success', 'User created successfully');
    }


    public function details($id)
    {
        $user = User::with('wallets')->findOrFail($id);
        $countries = Country::get(['id', 'name']);

        $deposit = collect([]);
        Deposit::where('user_id', $user->id)->where('user_type', 1)->with('currency')->get()->map(function ($q) use ($deposit) {
            $deposit->push((float) amountConv($q->amount, $q->currency));
        });
        $data['totalDeposit'] = $deposit->sum();

        $withdraw = collect([]);
        Withdrawals::where('user_id', $user->id)->with('currency')->get()->map(function ($q) use ($withdraw) {
            $withdraw->push((float) amountConv($q->amount, $q->currency));
        });
        $data['totalWithdraw'] = $withdraw->sum();

        return view('admin.user.details', compact('user', 'countries', 'data'));
    }

    public function profileUpdate(Request $request, $id)
    {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users,email,' . $id,
            'phone' => 'required',
            'country' => 'required',
        ]);

        $user          = User::findOrFail($id);
        $user->name    = $request->name;
        $user->email   = $request->email;
        $user->phone   = $request->phone;
        $user->country = $request->country;
        $user->city    = $request->city;
        $user->zip     = $request->zip;
        $user->address = $request->address;
        $user->status  = $request->status ? 1 : 0;
        $user->email_verified  = $request->email_verified ? 1 : 0;
        $user->kyc_status  = $request->kyc_status ? 1 : 0;
        $user->update();

        return back()->with('success', 'User profile updated');
    }


    public function modifyBalance(Request $request)
    {
        $request->validate([
            'wallet_id' => 'required',
            'user_id'   => 'required',
            'amount'    => 'required|gt:0',
            'type'      => 'required|in:1,2'
        ]);
        $user  = User::findOrFail($request->user_id);
        $wallet = Wallet::where('id', $request->wallet_id)->where('user_id', $request->user_id)->where('user_type', 1)->firstOrFail();

        if ($request->type == 1) {
            $wallet->balance += $request->amount;
            $wallet->update();

            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $request->user_id;
            $trnx->user_type   = 1;
            $trnx->currency_id = $wallet->currency->id;
            $trnx->amount      = $request->amount;
            $trnx->charge      = 0;
            $trnx->remark      = 'add_balance';
            $trnx->type        = '+';
            $trnx->details     = trans('Balance added by system');
            $trnx->save();

            $msg = 'Balance has been added';

            @mailSend(
                'add_balance',
                [
                    'amount' => amount($request->amount, $wallet->currency->type, 2),
                    'curr'  => $wallet->currency->code,
                    'trnx'  => $trnx->trnx,
                    'after_balance' => amount($wallet->balance, $wallet->currency->type, 2),
                    'date_time'  => dateFormat($trnx->created_at)
                ],
                $user
            );
        }
        if ($request->type == 2) {
            $wallet->balance -= $request->amount;
            $wallet->update();

            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $request->user_id;
            $trnx->user_type   = 1;
            $trnx->currency_id = $wallet->currency->id;
            $trnx->amount      = $request->amount;
            $trnx->charge      = 0;
            $trnx->remark      = 'subtract_balance';
            $trnx->type        = '-';
            $trnx->details     = trans('Balance subtracted by system');
            $trnx->save();

            $msg = 'Balance has been subtracted';

            @mailSend(
                'subtract_balance',
                [
                    'amount' => amount($request->amount, $wallet->currency->type, 2),
                    'curr'  => $wallet->currency->code,
                    'trnx'  => $trnx->trnx,
                    'after_balance' => amount($wallet->balance, $wallet->currency->type, 2),
                    'date_time'  => dateFormat($trnx->created_at)
                ],
                $user
            );
        }

        return back()->with('success', $msg);
    }

    public function login($id)
    {
        $user = User::findOrFail($id);
        Auth::guard('web')->loginUsingId($user->id);
        return redirect(route('user.dashboard'));
    }

    public function loginInfo($id)
    {
        $user = User::findOrFail($id);
        $loginInfo = LoginLogs::where('user_id', $id)->latest()->paginate(15);
        return view('admin.user.login_info', compact('loginInfo', 'user'));
    }
}
