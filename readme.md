# image-exchanger
Extract Images from HTML code in database data field and Exchange image url from old to new

## Benefit
이 패키지는 추출한 이미지를 엑셀파일로 덤프합니다. 덤프한 엑셀 파일을 조작하여 커스터마이즈할 수 있습니다. 
중간에 작업이 실패해도 엑셀파일을 수정하여 재수행할 수 있습니다.

## How to install

    composer require evanskim/image-exchanger

## How to use
라라벨 5.4 이하 버전에서는 서비스프로바이더를 등록하세요. 5.5 이상은 자동 등록됩니다.
    
    # config/app.php
    'providers' => [
        ...
        /*
        * Package Service Providers...
        */
        EvansKim\ImageExchanger\ImageExchangerServiceProvider::class,
        ...
    ]
  
### 설정파일 퍼블리싱 하고 설정을 변경하세요.

    php artisan vendor:publish --tag=public
    
    # config/image-exchanger.php
    return [
        'table' => 'g4_write_notice', #사용할 테이블명
        'primary_id' => 'wr_id', #테이블의 프라이머리 필드명
        'field' => 'wr_content', # HTML이 담긴 필드명
        'storage' => 'public' # 파일을 저장할 스토리지명
    ];
  
### 실행

    php artisan image-exchanger:run
    
## How to process
1. 데이터를 가져옵니다.
2. 이미지를 추출하여 엑셀파일(extracts.xls)로 덤프 합니다.
3. extracts.xls 을 기준으로 이미지 파일을 가져와 스토리지에 저장합니다.
4. 성공한 내역(success.xls)과 실패한 내역(fail.xls)을 엑셀파일로 각각 덤프합니다.
5. success.xls 파일로 데이터의 이미지 url을 변경하고 저장합니다. 
