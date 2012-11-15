<?php
/**
 * easykvs.php
 *
 * 簡易 Key-Value-Store API 設置クラス
 *
 * @php_version >= php5
 * @licence MIT Licence <http://www.opensource.org/licenses/mit-license.php>
 * @author  kjirou <sorenariblog[at]google[dot]com> <http://kjirou.sakura.ne.jp/mt/>
 */
class EasyKVSError extends Exception {}
class EasyKVSNgResponseError extends EasyKVSError {}

class EasyKVS {

    const VERSION = '0.1.1';
    const RELEASED_AT = '2012-05-09 00:00:00';

    /** データ格納ディレクトリへの相対パス */
    private $data_dir = './data';

    /**
     * 各キー／全体のデータサイズ上限(kb)
     *
     * - 本初期値は mixiアプリの Persistence API のサイズ上限を参考にした
     *   おそらくは 1024 で計算だが、わかり易さと念のために1k単位で計算
     *   キーを含めた容量なのかは不明
     *   ref) http://developer.mixi.co.jp/appli/spec/pc/share_info/
     */
    public $max_value_length = 64000; // 64 * 1k
    public $max_data_size = 10000000; // 10 * 1mb

    /** データファイル保存期間(秒)／データファイル削除処理の頻度
        この期間アクセスが無いファイルは 1/頻度 の割合で削除対象になる */
    public $life_time = 604800; // 86400 * 7days
    #public $gc_frequency = null; // int=1以上,その回数アクセスに1回実行 || null=実行しない

    /** データディレクトリへ
        データ直接アクセス禁止用の.htaccessファイルを自動生成するか */
    public $enable_auto_htaccess = true;

    /** 解釈対象となるリクエストパラメータ, add_parameters参照 */
    private $params = null;

    /**
     * 処理モードの全種類／現在の処理モード／リクエストパラメータ上でのキー
     *
     * OpenSocial上の以下に相当する
     * 'fetch'  = newFetchPersonAppDataRequest
     * 'update' = newUpdatePersonAppDataRequest
     * 'remove' = newRemovePersonAppDataRequest
     */
    private $all_modes = array('fetch', 'update', 'remove'); // [0]=指定無し時のデフォルト
    private $mode = null;
    public $mode_key = '__mode__';

    /** 個人ID, ユーザを特定するキーでいわゆるユーザ名に相当
        OpenSocial上の PERSON_ID を想定 */
    private $person_id = null;
    public $person_id_key = '__person_id__';

    /** JSONP用コールバック関数名, null で括らない
        ちなみに jQuery.ajax の場合は 'jQuery1705597869567432656_1326204897902'
        というような関数名を指定してくる */
    private $jsonp_callback = null;
    public $jsonp_callback_key = '__jsonp__';


    /** 解釈させるリクエストパラメータを設定する
        通常 $_GET または $_POST またはその両方をそれぞれ設定, $_COOKIE も指定することは可能
        なお、$_REQUEST は $_COOKIE も含まれるので注意 */
    public function add_parameters($params) {
        if ($this->params === null) $this->params = array();
        $this->params = array_merge($this->params, $params);
    }

    public function set_data_dir($value) {
        $this->data_dir = $value;
    }

    /** 処理を行う */
    public function execute() {

        if ($this->params === null) {// 設定ミス
            throw new EasyKVSError('EasyKVS.process, none params');
        }

        $this->apply_mode();
        $this->apply_person_id();
        $this->apply_jsonp_callback();

        // データディレクトリが無ければ生成する
        if (file_exists($this->data_dir) === false) {
            mkdir($this->data_dir); //! 第2引数に 0777 入れたけどダメだった、詳細不明
            chmod($this->data_dir, 0777);
        }

        // .htaccess自動生成オプション
        if ($this->enable_auto_htaccess) {
            $htaccess_path = $this->data_dir . '/.htaccess';
            if (file_exists($htaccess_path) === false) {
                $fh = fopen($htaccess_path, 'w');
                fwrite($fh, "Order Deny,Allow\nDeny from All");
                fclose($fh);
                chmod($htaccess_path, 0777);
            }
        }

        #// データファイル削除処理, 数アクセスに1回実行する
        #if ($this->gc_frequency !== null && rand(1, $this->gc_frequency) === 1) {
        #    $this->clean_data_file();
        #}

        // エンドユーザによる誤った操作は EasyKVSNgResponseError を投げる
        try {
            if ($this->person_id === null) {
                throw new EasyKVSNgResponseError("None `{$this->person_id_key}` in params");
            }
            if ($this->mode === 'fetch') {
                return $this->fetch();
            } else if ($this->mode === 'update') {
                return $this->update();
            } else if ($this->mode === 'remove') {
                return $this->remove();
            }
        } catch (EasyKVSNgResponseError $err) {
            $this->output_response('ng', $err->getMessage());
            return;
        }
    }

    /** fetch処理を行う */
    private function fetch() {
        list($file_name, $file_dir, $file_path) = $this->create_file_path_infos();
        if (is_file($file_path) === false) {// データが無い
            throw new EasyKVSNgResponseError("Data not found");
        };
        $data_text = file_get_contents($file_path);
        $data = $this->parse_data_text($data_text);

        $this->output_response('ok', 'Successed fetching data', $data);
        return;
    }

    /** update処理を行う */
    private function update() {

        $data = $this->params_to_data();

        if (count($data) === 0) {// データ無し
            throw new EasyKVSNgResponseError("None data");
        };

        list($file_name, $file_dir, $file_path) = $this->create_file_path_infos();

        // データファイルが無い場合は新規作成
        if (file_exists($file_path)) {
            // pass
        } else {// 無し
            // ディレクトリ作成
            //! umaskしないとmkdirでは0755になってしまう
            $mask = umask(0);
            @mkdir($file_dir, 0777, true);
            umask($mask);
            // ファイル作成
            $fh = fopen($file_path, 'w');
            fwrite($fh, '');
            fclose($fh);
            chmod($file_path, 0777);
        };

        // 記録済みデータがあれば取得してデータを結合
        $merged_data = array();
        $saved_data = array();
        $saved_data_text = file_get_contents($file_path);
        if ($saved_data_text !== '') {// 新規作成時以外
            $saved_data = $this->parse_data_text($saved_data_text);
        }
        $merged_data = array_merge($saved_data, $data);

        // 総容量チェック
        $data_size = $this->calc_data_size($merged_data);
        if ($data_size > $this->max_data_size) {
            throw new EasyKVSNgResponseError("Overflow data-size=`{$data_size}`");
        }

        // テキスト化して保存
        $new_data_text = $this->create_data_text($merged_data);
        file_put_contents($file_path, $new_data_text);

        $this->output_response('ok', "Successed updating data, data-size=`{$data_size}`");
        return;
    }

    /** remove処理を行う */
    private function remove() {
        list($file_name, $file_dir, $file_path) = $this->create_file_path_infos();
        if (is_file($file_path) === false) {
            throw new EasyKVSNgResponseError("Data not found");
        };
        unlink($file_path);

        $this->output_response('ok', "Successed removing data");
        return;
    }

    /** パラメータを整理してKey-Valueデータ部分のみを取り出す */
    private function params_to_data() {
        $data = array();
        foreach ($this->params as $k => $v) {
            if (
                // 予約キーは除外
                $k === $this->mode_key ||
                $k === $this->person_id_key ||
                $k === $this->jsonp_callback_key ||

                // 'a[b]=c' のようなリクエストパラメータを指定した場合配列になる
                // 他にもPHPの特殊解釈があるかもしれないので文字列以外は除外
                is_string($v) === false ||

                // 書式判定
                //   \t        ... データファイル内でkey=valueの区切り文字に使っているため
                //   [ or ]    ... PHPの仕様で、これを含むキーは特殊解析されるため無理
                //                 例えば 'a[b]=c' は $_REQUEST['a']['b'] = 'c' になる
                //   .(ドット) ... 確か、これも何かPHPの仕様であった気がする、ので除外
                preg_match('/^[-_:;{}a-zA-Z0-9]{1,32}$/', $k) === 0
            ) {
                continue;
            }
            // 1キーのデータサイズが上限を超えている場合
            if (strlen($v) > $this->max_value_length) {
                throw new EasyKVSNgResponseError("`{$k}`'s value size is too big");
            }
            $data[$k] = $v;
        }
        return $data;
    }

    /** データファイルパスに関する情報を返す */
    private function create_file_path_infos() {
        $file_name = $this->person_id . '.txt';
        // 中間ディレクトリを決定する
        // - person_idの先頭3文字がそれぞれディレクトリ名になる
        //   例えば 'kjirou' なら 'k/j/i/kjirou.txt' に配置される
        // - person_idはここを通る時は必ず4文字以上の前提
        $dir1 = substr($this->person_id, 0, 1);
        $dir2 = substr($this->person_id, 1, 1);
        $dir3 = substr($this->person_id, 2, 1);
        $file_dir = "{$this->data_dir}/{$dir1}/{$dir2}/{$dir3}";
        $file_path = "{$file_dir}/{$file_name}";
        return array($file_name, $file_dir, $file_path);
    }

    /**
     * データファイルパスリストを取得する, .txt ファイルのみ対象
     *
     * @param hash $conds 追加の抽出条件
     *               in_file_names => array(ファイル名リスト), そのファイル名と等しいファイルのみ
     *               atime_less_than => int, タイムスタンプ, 最終アクセス時刻がこれより古いファイルのみ
     * @return arr [ファイルパス1, ファイルパス2, ...] || false=何らかの理由で失敗
     */
    private function find_data_file_paths($conds = array()) {

        $in_file_names = (array_key_exists('in_file_names', $conds))? $conds['in_file_names']: null;
        $atime_less_than = (array_key_exists('atime_less_than', $conds))? $conds['atime_less_than']: null;

        if (is_dir($this->data_dir) === false) return false;

        $dh = opendir($this->data_dir);
        if ($dh === false) return false;

        $file_paths = array();
        while (($file_name = readdir($dh)) !== false) {
            $file_path = $this->data_dir . '/' . $file_name;
            // ファイルではない
            if (is_file($file_path) === false) continue;
            // .txtではない
            if (preg_match('/\\.txt$/', $file_path) === 0) continue;
            // ファイル名条件
            if ($in_file_names !== null && !in_array($file_name, $in_file_names, true)) continue;
            // 最終アクセス時刻条件
            if ($atime_less_than !== null) {
                $stat = stat($file_path);
                if ($stat['atime'] >= $atime_less_than) continue;
            };

            $file_paths[] = $file_path;
        }

        closedir($dh);
        return $file_paths;
    }

    /**
     * KVデータを保存用データファイル形式に加工する
     *
     * @param hash $key_value_sets
     * @return str 以下のような形式のテキスト, valueはbase64エンコード済み
     *             ---------------------------
     *             key1\tvalue1\n
     *             key2\tvalue2\n
     *             key3\tvalue3\n
     *             ---------------------------
     */
    private function create_data_text($key_value_sets) {
        $text = '';
        foreach ($key_value_sets as $k => $v) {
            $text .= $k . "\t" . base64_encode($v) . "\n";
        }
        return $text;
    }

    /** 上記 create_data_text で作成したファイルの解析, @return hash */
    private function parse_data_text($data_text) {
        $data = array();
        $data_text = preg_replace("/\n$/", '', $data_text);
        $lines = explode("\n", $data_text);
        foreach ($lines as $nouse => $line) {
            list($key, $value) = explode("\t", $line);
            $data[$key] = base64_decode($value);
        }
        return $data;
    }

    /** 配列の全値を合計したデータサイズを返す, キーのサイズは含まない */
    private function calc_data_size($arr) {
        $size = 0;
        foreach ($arr as $nouse => $v) {
            $size += strlen($v);
        }
        return $size;
    }

    /** 当アプリ共通形式のレスポンス内容を生成する */
    private function create_response_body($status, $message = null, $data = null) {
        // 成否判定などの状況を示すデータは 'ok' と 'ng' だけ
        // なお、HTTPステータスは常に200番を返す
        $status = (in_array($status, array('ok', 'ng'), true))? $status: 'ok';

        $json = json_encode(array(
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ));

        if ($this->jsonp_callback !== null) {
            return "{$this->jsonp_callback}({$json})";
        } else {
            return $json;
        }
    }

    /** 上記をHTTP出力する */
    private function output_response(/* args passing */) {
        $args = func_get_args();
        $response_body = call_user_func_array(array($this, 'create_response_body'), $args);
        header('Content-type: text/plain');
        header('HTTP/1.0 200 OK'); // 成否を問わずに200
        echo $response_body;
    }

    /** パラメータから処理モードを格納する */
    private function apply_mode() {
        if (
            array_key_exists($this->mode_key, $this->params) &&
            in_array($this->params[$this->mode_key], $this->all_modes, true)
        ) {
            $this->mode = $this->params[$this->mode_key];
        } else {
            $this->mode = $this->all_modes[0];
        }
    }

    /** パラメータから個人IDを格納する */
    private function apply_person_id() {
        // 許容可能文字はテキトウに決めている
        //   4文字以上へ変更したのは、とりあえずはIDを分割してのディレクトリ振り分けのために
        //   文字数が2文字以上必要だったから
        //   いっそのこと世間的に許容されそうな4文字以上にしてみた
        // '-'は、データファイルをコマンドラインで直接消すときに面倒になるので外した
        if (
            array_key_exists($this->person_id_key, $this->params) &&
            preg_match('/^[_a-zA-Z0-9]{4,32}$/', $this->params[$this->person_id_key]) > 0
        ) {
            $this->person_id = $this->params[$this->person_id_key];
        }
    }

    /** パラメータからJSONP用コールバック関数名を格納する */
    private function apply_jsonp_callback() {
        if (
            array_key_exists($this->jsonp_callback_key, $this->params) &&
            preg_match('/^[_$a-zA-Z0-9]{1,128}$/', $this->params[$this->jsonp_callback_key]) > 0
        ) {
            $this->jsonp_callback = $this->params[$this->jsonp_callback_key];
        }
    }

    #/** 有効期限切れのデータファイルを削除する */
    #private function clean_data_file(){
    #    $atime_limit = time() - $this->life_time;
    #    $paths = $this->find_data_file_paths(array(
    #        'atime_less_than' => $atime_limit,
    #    ));
    #    foreach ($paths as $nouse => $file_path) {
    #        unlink($file_path);
    #    };
    #}


    /**
     * 生成メソッド
     *
     * 自動で各種設定を行い開始も行うお便利版メソッド
     *
     * 設定を変更したい場合は、以下のように new EasyKVS() から生成して設定する
     * 以下は設定例で、項目種別と詳細はメンバ変数のコメントを参照
     * -------------------------
     * <?php
     * $kvs = new EasyKVS()
     * $kvs->add_parameters($_POST); // POST 以外の値を受け取りたくない
     * $kvs->jsonp_callback_key = 'foo'; // JSONPコールバック関数名は 'foo=myCallback' で指定したい
     * $kvs->life_time = 86400; // 自動削除時間を短くしたい
     * $kvs->max_data_size = 1000000; // 1ユーザのデータ量上限を 1mb に抑えたい
     * $kvs->execute(); // 最後に呼ぶ
     * ?>
     * -------------------------
     *
     * @param $data_dir データ保存ディレクトリへの相対パス
     *                  設定しない場合は ./data となる
     * @return EasyKVSオブジェクト, 今のところexecute後に参照する必要はないけど
     */
    static public function auto_start($data_dir = null) {
        global $_GET, $_POST;
        $obj = new self();
        if ($data_dir !== null) $obj->set_data_dir($data_dir);
        $obj->add_parameters($_GET);
        $obj->add_parameters($_POST);
        $obj->execute();
        return $obj;
    }


    // ---------------------------------------------------------------------
    //
    // 以下、開発中にテストのために書いたコード
    //
    // 環境確認も含むので、設置時に実行直前で一回実行しとくと吉
    // Fatalエラーが返ってしまったら、環境が悪いかコードがバグってるか理由はともかく正常に動かない
    //
    // @param $data_dir データ保存場所へのパス, 指定しない場合は初期値が試される
    //
    static public function test($data_dir = './data') {

        // 権限確認
        if (file_exists($data_dir)) {
            if (is_writable($data_dir) === false) self::e("permission denied for `{$data_dir}`");
        } else {
            $parent_dir = dirname($data_dir);
            if (is_writable($parent_dir) === false) self::e("permission denied for `{$parent_dir}`");
        }

        // 環境確認
        // ! PHPバージョン確認は、取得方法が phpinfo のHTMLパース以外見つからなかったので止めた
        if (function_exists('json_encode') === false || function_exists('json_decode') === false) {
            self::e('none `json_encode` or `json_decode` function in your php');
        }

        // 初期化手順漏れ時にエラーを返すか
        $kvs = new self();
        try { $kvs->execute(); self::e(); } catch (EasyKVSError $e) {}


        //
        // 異常系チェック, ngレスポンスを正しく返すか
        //

        // 個人ID無し
        $p = self::dummy_params();
        unset($p['__person_id__']);
        $kvs = self::test_factory($p);
        if (self::is_ng_execution($kvs) !== true) self::e();

        // 更新時、データ無し
        $p = self::dummy_params('update');
        $o = self::test_factory($p);
        if (self::is_ng_execution($o) !== true) self::e();

        // 更新時、1キーのサイズ超過
        $p = self::dummy_params('update');
        $big_value = 'a'; // 1byte
        for ($i = 0; $i < 1000; $i++) {
            $big_value .= '1111111111222222222233333333334444444444555555555566666666667777';//64b*1000
        }// = 640001 bytes
        $p['bigsize'] = $big_value;
        $o = self::test_factory($p);
        if (self::is_ng_execution($o) !== true) self::e();

        //// 更新時、全体のサイズ超過, !メモリ超過になりそうなのでコメントアウト
        //$p = self::dummy_params('update');
        //for ($i = 0; $i < 200; $i++) {// 64kb*200=12.800kb > 10mb
        //    $p[strval($i)] = substr($big_value, 1);// 1byte削る
        //};
        //$o = self::test_factory($p);
        //if (self::is_ng_execution($o) !== true) self::e();

        // 削除時、無いデータファイルを削除
        $p = self::dummy_params('remove');
        $p['__person_id__'] = 'sonzai_shimasen';
        $o = self::test_factory($p);
        if (self::is_ng_execution($o) !== true) self::e();


        //
        // 正常系をベースにシナリオテスト
        //
        error_reporting(E_ALL ^ E_WARNING); // HTTPヘッダ重複出力エラーを無視するため

        // テスト用IDを初期化
        $p = self::dummy_params('remove');
        $p['__person_id__'] = "goodman";
        $o = self::test_factory($p);
        $o->execute();

        $p = self::dummy_params('update');
        $p['__person_id__'] = "goodman";
        $p['a'] = "1";
        $p['b'] = "two";
        $p['c'] = "abc\ndef\nghi";
        $o = self::test_factory($p);
        $o->execute();

        $p = self::dummy_params('fetch');
        $p['__person_id__'] = "goodman";
        $o = self::test_factory($p);
        ob_start();
        $o->execute();
        $output = ob_get_contents();
        ob_end_clean();
        $data = json_decode($output, true); // true で連想配列化
        if (
            $data['status'] !== 'ok' ||
            $data['data']['a'] !== '1' ||
            count($data['data']) !== 3
        ) {
            self::e();
        };

        // a=更新 d=追加
        $p = self::dummy_params('update');
        $p['__person_id__'] = "goodman";
        $p['a'] = "11";
        $p['d'] = "new_key";
        $o = self::test_factory($p);
        $o->execute();

        $p = self::dummy_params('fetch');
        $p['__person_id__'] = "goodman";
        $o = self::test_factory($p);
        ob_start();
        $o->execute();
        $output = ob_get_contents();
        ob_end_clean();
        $data = json_decode($output, true);
        if (
            $data['data']['a'] !== '11' ||
            $data['data']['d'] !== 'new_key' ||
            count($data['data']) !== 4
        ) {
            self::e();
        };

        // また削除
        $p = self::dummy_params('remove');
        $p['__person_id__'] = "goodman";
        $o = self::test_factory($p);
        $o->execute();

        // 無い
        $p = self::dummy_params('fetch');
        $p['__person_id__'] = "goodman";
        $o = self::test_factory($p);
        if (self::is_ng_execution($o) !== true) self::e();

        // また更新
        $p = self::dummy_params('update');
        $p['__person_id__'] = "goodman";
        $p['_hoge'] = "1";
        $o = self::test_factory($p);
        $o->execute();

        // JSONP無し
        $p = self::dummy_params('fetch');
        $p['__person_id__'] = "goodman";
        $o = self::test_factory($p);
        ob_start();
        $o->execute();
        $output = ob_get_contents();
        ob_end_clean();
        if (preg_match('/^\{/', $output) === 0) self::e();

        // JSONP有り
        $p = self::dummy_params('fetch');
        $p['__person_id__'] = "goodman";
        $p['__jsonp__'] = "__myCallback";
        $o = self::test_factory($p);
        ob_start();
        $o->execute();
        $output = ob_get_contents();
        ob_end_clean();
        if (preg_match('/^__myCallback\(/', $output) === 0) self::e();
        if (preg_match('/\)$/', $output) === 0) self::e();

        #// 期限切れデータファイルの削除処理
        #$p = self::dummy_params('fetch');
        #$p['__person_id__'] = "goodman";
        #$o = self::test_factory($p);
        #$o->life_time = -1;//普通しない設定
        #$o->gc_frequency = 1;
        #if (self::is_ng_execution($o) !== true) self::e();
    }
    static private function e($msg = null) {// 以下、テストのための専用関数群
        if ($msg === null) $msg = '';
        throw new Exception('EasyKVS::test, ' . $msg);
    }
    static private function dummy_params($mode = null) {
        $p = array(
            '__person_id__' => 'tester',
        );
        if ($mode !== null) $p['__mode__'] = $mode;
        return $p;
    }
    static private function test_factory($params = null) {
        $obj = new self();
        if ($params !== null) {
            $obj->add_parameters($params);
        } else {
            $obj->add_parameters(self::dummy_params());
        }
        return $obj;
    }
    static private function is_ng_execution($kvs) {
        ob_start();
        $kvs->execute();
        $output = ob_get_contents();
        ob_end_clean();
        //header_remove(); // 5.3からだった
        if (preg_match('/"status":"ng"/', $output) > 0) { return true; }
        return false;
    }
}
