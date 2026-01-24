@extends('layouts.admin')

@section('title')
@lang('Add New User')
@endsection

@section('breadcrumb')
<section class="section">
    <div class="section-header d-flex justify-content-between">
        <h1>@lang('Add New User')</h1>
        <a href="{{route('admin.user.index')}}" class="btn btn-primary btn-sm"><i class="fas fa-backward"></i>
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
                <form class="row g-4" action="{{route('admin.user.store')}}" method="POST">
                    @csrf
                    <div class="col-sm-6 form-group">
                        <label class="form--label">@lang('Name')</label>
                        <input type="text" class="form-control form--control bg--section" name="name"
                            placeholder="@lang('Enter name')" required value="{{old('name')}}">
                    </div>
                    <div class="col-sm-6 form-group">
                        <label class="form--label">@lang('Email address')</label>
                        <input type="email" class="form-control form--control bg--section" name="email"
                            placeholder="@lang('Enter email')" required value="{{old('email')}}">
                    </div>
                    <div class="col-sm-6 form-group">
                        <label class="form--label">@lang('Country')</label>
                        <select name="country" class="form-control form--control bg--section country" required>
                            <option value="">@lang('Select')</option>
                            @foreach ($countries as $item)
                            <option value="{{$item->name}}" data-dial_code="{{$item->dial_code}}">{{$item->name}}
                            </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-sm-6 form-group">
                        <label class="form--label">@lang('Phone No.')</label>
                        <input type="hidden" name="dial_code">
                        <div class="input-group">
                            <span class="input-group-text d_code"></span>
                            <input type="text" name="phone" class="form-control form--control bg--section"
                                placeholder="@lang('Phone No.')" required value="{{old('phone')}}">
                        </div>
                    </div>

                    <div class="col-sm-12 form-group">
                        <label class="form--label">@lang('Address')</label>
                        <input type="text" name="address" value="{{old('address')}}"
                            class="form-control form--control bg--section" placeholder="@lang('Address')" required>
                    </div>
                    <div class="col-sm-6 form-group">
                        <label class="form--label">@lang('Password')</label>
                        <div class="input-group input-group-flat">
                            <input type="password" name="password" class="form-control form--control bg--section"
                                placeholder="@lang('Password')" autocomplete="off" required>
                        </div>
                    </div>
                    <div class="col-sm-6 form-group">
                        <label class="form--label">@lang('Confirm Password')</label>
                        <div class="input-group input-group-flat">
                            <input type="password" name="password_confirmation"
                                class="form-control form--control bg--section" placeholder="@lang('Confirm Password')"
                                autocomplete="off">
                        </div>
                    </div>

                    <div class="col-xl-12 form-group text-right">
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