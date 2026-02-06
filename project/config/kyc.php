<?php


return [

    // user_type = 1
    'individual' => [
        [
            'key' => 'first_name',
            'label' => 'First Name',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'last_name',
            'label' => 'Last Name',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'date_of_birth',
            'label' => 'Date of Birth',
            'type' => 'date',
            'required' => true,
        ],
        [
            'key' => 'gender',
            'label' => 'Gender',
            'type' => 'select',
            'required' => true,
            'options' => [
                ['value' => 'M', 'label' => 'Male'],
                ['value' => 'F', 'label' => 'Female'],
                ['value' => 'O', 'label' => 'Other'],
            ],
        ],
        [
            'key' => 'id_type',
            'label' => 'ID Type',
            'type' => 'select',
            'required' => true,
            'options' => [
                ['value' => 'NID', 'label' => 'National ID'],
                ['value' => 'PASSPORT', 'label' => 'Passport'],
            ],
        ],
        [
            'key' => 'id_number',
            'label' => 'ID Number',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'id_front',
            'label' => 'ID Front',
            'type' => 'image',
            'required' => true,
        ],
        [
            'key' => 'id_back',
            'label' => 'ID Back',
            'type' => 'image',
            'required' => true,
        ],
        [
            'key' => 'selfie',
            'label' => 'Selfie',
            'type' => 'image',
            'required' => true,
        ],
    ],

    // user_type = 2
    'company' => [
        [
            'key' => 'company_name',
            'label' => 'Company Name',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'registration_number',
            'label' => 'Registration Number',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'company_type',
            'label' => 'Company Type',
            'type' => 'select',
            'required' => true,
            'options' => [
                ['value' => 'LLC', 'label' => 'Limited Liability Company'],
                ['value' => 'PLC', 'label' => 'Public Limited Company'],
                ['value' => 'SOLE', 'label' => 'Sole Proprietorship'],
            ],
        ],
        [
            'key' => 'incorporation_date',
            'label' => 'Incorporation Date',
            'type' => 'date',
            'required' => true,
        ],
        [
            'key' => 'business_address',
            'label' => 'Business Address',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'director_name',
            'label' => 'Director Full Name',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'director_id_number',
            'label' => 'Director ID Number',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'certificate_of_incorporation',
            'label' => 'Certificate of Incorporation',
            'type' => 'image',
            'required' => true,
        ],
        [
            'key' => 'director_id_front',
            'label' => 'Director ID Front',
            'type' => 'image',
            'required' => true,
        ],
        [
            'key' => 'director_selfie',
            'label' => 'Director Selfie',
            'type' => 'image',
            'required' => true,
        ],
    ],

];
