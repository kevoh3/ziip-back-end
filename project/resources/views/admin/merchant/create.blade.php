@extends('layouts.admin')

@section('title')
@lang('Add New Merchant')
@endsection

@section('breadcrumb')
<section class="section">
    <div class="section-header d-flex justify-content-between">
        <h1>@lang('Add New Merchant')</h1>
        <a href="{{route('admin.merchant.index')}}" class="btn btn-primary btn-sm"><i class="fas fa-backward"></i>
            @lang('Back')</a>
    </div>
</section>
@endsection

@section('content')
<div class="row justify-content-center">

    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                @include('admin.partials.form-both')
                <form action="{{route('admin.merchant.store')}}" method="POST" action="" class="needs-validation">
                    @csrf
                    <div class="form-group">
                        <label>@lang('Business Name')</label>
                        <input type="text" class="form-control  @error('business_name') is-invalid  @enderror"
                            name="business_name" tabindex="1" required value="{{old('business_name')}}">
                        @error('business_name')
                        <span class="text-danger mt-2">{{ __($message) }}</span>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label>@lang('Your Name')</label>
                        <input type="text" class="form-control  @error('name') is-invalid  @enderror" name="name"
                            tabindex="1" required value="{{old('name')}}">
                        @error('name')
                        <span class="text-danger mt-2">{{ __($message) }}</span>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="email">@lang('Email')</label>
                        <input id="email" type="email" class="form-control  @error('email') is-invalid  @enderror"
                            name="email" tabindex="1" value="{{old('email')}}" required>
                        @error('email')
                        <span class="text-danger mt-2">{{ __($message) }}</span>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label>@lang('Country')</label>
                        <select name="country" class="form-control country" required>
                            <option value="">@lang('Select')</option>
                            @foreach ($countries as $item)
                            <option value="{{$item->name}}" data-dial_code="{{$item->dial_code}}">{{$item->name}}
                            </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label>@lang('Phone')</label>
                        <div class="input-group">
                            <input type="hidden" name="dial_code">
                            <div class="input-group-prepend">
                                <span class="input-group-text d_code"></span>
                            </div>
                            <input type="phone" class="form-control @error('phone') is-invalid  @enderror" name="phone"
                                tabindex="2">
                            @error('phone')
                            <span class="text-danger mt-2">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                    <div class="form-group">
                        <label>@lang('Address')</label>
                        <input type="text" class="form-control  @error('address') is-invalid  @enderror" name="address"
                            tabindex="1" value="{{old('address')}}" required>
                        @error('address')
                        <span class="text-danger mt-2">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="password" class="control-label">@lang('Password')</label>
                        <input id="password" type="password"
                            class="form-control @error('password') is-invalid  @enderror" name="password" tabindex="2">
                        @error('password')
                        <span class="text-danger mt-2">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label for="password" class="control-label">@lang('Confirm Password')</label>
                        <input id="password" type="password"
                            class="form-control @error('password_confirmation') is-invalid  @enderror"
                            name="password_confirmation" tabindex="2">
                        @error('password_confirmation')
                        <span class="text-danger mt-2">{{ $message }}</span>
                        @enderror
                    </div>


                    <div class="form-group text-right">
                        <button class="btn  btn-primary btn-lg" type="submit">@lang('Submit')</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('script')

<script>
    'use strict';
      $(document).on('change','.country',function () {
        var code = $(this).find(':selected').data('dial_code');
        $('.d_code').text(code)
        $('input[name=dial_code]').val(code)
      })

</script>
@endpush