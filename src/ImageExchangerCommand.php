<?php

namespace EvansKim\ImageExchanger;

use App\Media;
use App\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use League\Flysystem\FileNotFoundException;
use Symfony\Component\Finder\SplFileInfo;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImageExchangerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'image-exchanger:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract Images from HTML code in database data field and Exchange image url from old to new';

    protected $images = [];
    protected $fails = [];
    protected $results = [];
    protected $config = [];
    protected $dom;
    public function __construct()
    {
        $this->config = config('image-exchanger');
        $this->dom = new \DOMDocument();
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Table name : ' .$this->config['table']  );
        $this->info('Target field : ' .$this->config['field']  );
        if( !$this->confirm( 'Continue?' ) ){
            return false;
        }
        $flag = $this->confirm("Extract Images.");
        if (!$flag) {
            $this->alert("Bypass");
        } else {
            $this->getExcelFile();
        }
        $flag = $this->confirm("Copy to {$this->config['storage']}");
        if (!$flag) {
            $this->alert("Bypass");
        } else {
            $this->moveFileToStorage();
        }
        $flag = $this->confirm("Exchange all image urls?");
        if (!$flag) {
            $this->alert("Bypass");
        } else {
            $this->changeUrls();
        }
    }

    /**
     * 상품안에 상세페이지 내역을 조회하여 src 이미지를 모두 추출합니다!
     */
    protected function getExcelFile()
    {
        $this->getProductDetailImages();
        $this->exportToExcel();
    }
    /**
     * 엑셀에 리스트 된 내역을 모두 스토리지에 옮기고 그 결과를 엑셀로 출력합니다.
     */
    protected function moveFileToStorage()
    {
        Excel::load(storage_path('/temp/extracts.xls'))->get()->map(function ($item) {

            if ($this->isSkipCopy($item)) {
                // 이미 스토리지 안에 들어간 이미지는 스킵합니다.
                return false;
            }

            $item[2] = explode(",", $item[2]);
            if (count($item[2]) === 1) {
                $subDir = $item[2][0];
            } else {
                $subDir = 'shares';
            }

            $newPath = $this->getNewPath($item, $subDir);
            $stream = $this->getStream($item);
            if(!$stream){
                return false;
            }

            $newUrl = $this->putStreamGetUrl($newPath, $stream);

            // 키값, 타겟, 새로운 값, 적용되는 상품 아이디
            $this->results[] = [$item[3], $item[0], $newUrl, implode(",", $item[2])];
        });
        Excel::create('success', function ($excel) {

            $excel->sheet('Sheet1', function ($sheet) {

                $sheet->fromArray($this->results);

            });

        })->store('xls', storage_path('/temp'));
        $this->info("success.xls created");
        Excel::create('fail', function ($excel) {

            $excel->sheet('Sheet1', function ($sheet) {

                $sheet->fromArray($this->fails);

            });

        })->store('xls', storage_path('/temp'));
        $this->info("fail.xls created");
    }
    /**
     * 엑셀파일에 파일 이동에 성공한 데이터만 상세 내역에서 해당 링크를 변경합니다.
     */
    protected function changeUrls()
    {
        $products = $this->getCollectionByPrimary();
        // 상품상세내역에서 이미지 뽑아 내기
        Excel::load(storage_path('/temp/success.xls'))->get()->map(function ($item) use ($products) {
            /**
             * @var $item array [ 키값, 타겟, 새로운 값, 적용되는 상품 아이디]
             */
            $item[3] = explode(",", $item[3]);

            foreach ($item[3] as $it_id) {

                $this->exchangeUrl($item, $products, $it_id);

            }
        });

        $this->saveExchangedData($products);
    }

    protected function exportToExcel($name = 'extracts')
    {
        Excel::create($name, function ($excel) {

            $excel->sheet('Sheet1', function ($sheet) {

                $sheet->fromArray($this->images);

            });

        })->store('xls', storage_path('/temp'));
    }

    /**
     * @param $cleanSrc
     * @return string
     */
    protected function getFileName($cleanSrc)
    {
        return pathinfo($cleanSrc, PATHINFO_BASENAME);
    }

    /**
     * @param $src
     * @return bool
     */
    protected function isEncodedOrNull($src)
    {
        return !$src || preg_match('/data\:image.*/ui', $src);
    }

    /**
     * @param $src
     * @return string
     */
    protected function getCleanSrcUrl($src)
    {
        return trim(preg_replace("/https?.*(\.kr|\.net|\.com)(:\d{0,4})?/", '', $src));
    }
    /**
     * @param $product
     * @return mixed
     */
    protected function getContent($product)
    {
        $contents = $product->it_explan;
        return $contents;
    }
    /**
     * @param $product
     * @return mixed
     */
    protected function getPrimary($product)
    {
        return $product->it_id;
    }
    protected function getProductDetailImages($isStacked = null)
    {
        $this->getCollection()
            ->map(function ($product) use ($isStacked) {

                $contents = $this->getContent($product);
                // 내용이 없으면 통과
                if (!$contents) {
                    return false;
                }

                libxml_use_internal_errors(true);
                $this->dom->loadHTML($contents);
                libxml_clear_errors();

                $imgs = $this->dom->getElementsByTagName('img');

                if (!$imgs->length) {
                    return false;
                }

                foreach ($imgs as $img) {
                    $src = $img->getAttribute('src');

                    if ($this->isEncodedOrNull($src)) {
                        continue;
                    }

                    $cleanSrc = $this->getCleanSrcUrl($src);

                    $filename = $this->getFileName($cleanSrc);

                    if (!empty($this->images[$cleanSrc])) {
                        if (is_null($isStacked)) {
                            $this->images[$cleanSrc][2] .= "," . $this->getPrimary($product);
                        } else {
                            $this->images[$cleanSrc][2] = "shares";
                        }
                    } else {
                        $this->images[$cleanSrc] = [$src, $filename,  $this->getPrimary($product), $cleanSrc];
                    }
                }
            });
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    protected function getCollection()
    {
        if( $this->config['eloquent'] !== false && is_callable($this->config['eloquent']) ){
            return $this->config['eloquent']();
        }else{
            return DB::table($this->config['table'])->get();
        }

    }
    /**
     * @param $item
     * @return false|int
     */
    protected function isSkipCopy($item)
    {
        return true; //preg_match("/\/storage\/.*/ui", $item[3])
    }
    /**
     * @param $item
     * @param $subDir
     * @return string
     */
    protected function getNewPath($item, $subDir)
    {
        return implode(DIRECTORY_SEPARATOR, [$this->config['table'], $subDir, $item[1]]);
    }
    /**
     * @param $item
     * @return bool|false|resource
     */
    protected function getStream($item)
    {
        $path = $item[0];
        $this->info($path . " processing");
        try{
            $file = file_get_contents($path);
        }catch (\ErrorException $exception){
            $this->error($path . " not found.");
            $item[2] = implode(",", $item[2]);
            $this->fails[] = $item;
            return false;
        }

        return $file;
    }

    /**
     * @param $newPath
     * @param $stream
     * @return string
     */
    protected function putStreamGetUrl($newPath, $stream)
    {
        Storage::disk($this->config['storage'])->put($newPath, $stream);
        return Storage::disk($this->config['storage'])->url($newPath);
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    protected function getCollectionByPrimary()
    {
        return $this->getCollection()->keyBy('wr_id');
    }
    /**
     * @param $item
     * @param $collection \Illuminate\Support\Collection
     * @param $primary_id
     */
    protected function exchangeUrl($item, $collection, $primary_id)
    {
        $collection[$primary_id]->wr_content = str_replace($item[1], $item[2], $collection[$primary_id]->wr_content);
    }
    /**
     * @param $collection Collection
     */
    protected function saveExchangedData($collection)
    {
        $collection->filter(function ($row) {
            return DB::table($this->config['table'])
                ->where([
                    $this->config['primary_id']=>$row->{$this->config['primary_id']}
                ])
                ->update([
                    $this->config['field']=>$row->{$this->config['field']}
                ]);
        });
    }
}
