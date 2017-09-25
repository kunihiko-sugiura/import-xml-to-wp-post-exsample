<?php
require_once 'vendor/autoload.php';

// ** dotenv load
$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

// ** file to xml
$xml_string = file_get_contents('./import/blog_export.xml', true);

// ** xml text to php obj
$xml = new SimpleXMLElement( $xml_string , LIBXML_NOCDATA);
$json = json_encode($xml);
$obj = json_decode($json, TRUE);

const TERM_TAXONOMY_ID = 1;
const FILE_UPLOAD_URL = $_ENV['FILE_UPLOAD_URL'];

$pdo = null;
try {
	// ** connect to mysql
	$dsn = 'mysql:dbname=' . $_ENV['DB_NAME'] . ';host='  . $_ENV['DB_HOST'] . ';port='  . $_ENV['DB_PORT'] . ";charset=utf8";
	$pdo = new \Slim\PDO\Database( $dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'] );

	$pdo->beginTransaction();

    // ** import to wp
    foreach ( $obj["article"] as $article) {
    	$wp_aricle_id = null;
    	// ** WordPressのデータ形式について
		// **  基本は post_content, post_title, post_status, post_type

		// 	* post_status	: publish,future,draft,private
		//  * post_type		: 投稿タイプ
		//    * 投稿                   post
		//    * 固定ページ              page
		//    * 添付ファイル            attachment  (wpでアップロードされたファイルについて、ファイル名や説明などの情報を保持)
		//    * リビジョン              revision
		//    * ナビゲーションメニュー    nav_menu_item
		//	  * wp_terms.term_id( カテゴリ管理、名称 )
		//			-> wp_term_taxonomy.term_taxonomy_id 
		//			-> wp_term_relationships.object_id
		//			-> wp_posts.ID
		//  * post_content		: 本文 htmlタグ使用可能,<div><br></div>とか突っ込んでも、wp的に無視される場合があるので注意
		//  * post_title		:タイトル
		//  * comment_status	:コメント設定			closed,open
		//  * ping_status		:トラックバック設定	closed,open

    	$stmt_posts = $pdo->select()
                       ->from('wp_posts')
                       ->where( 'post_date', '=', $article["open_time"])
                       ->execute();
		$wp_post_data = $stmt_posts->fetch();

		$post_content = $article["body"];

		if( isset($article["photo"]) && $article["photo"] ) {
			$photos = split(",", strtolower($article["photo"]));
			foreach ($photos as $file_name) {
				$post_content .= '<img class="alignnone size-full" src="' . FILE_UPLOAD_URL . $file_name . '" />';
			}
		}

		$import_article = [
			"post_author"		=> 1,
			"post_date"			=> $article["open_time"],
			// "post_date_gmt"		=> $article["open_time"],
			"post_title"		=> $article["title"] ? $article["title"] : "",
			"post_content"		=> $post_content,
			"post_status"		=> $article["open"] ? 'publish' : "private",
			"post_name"			=> urlencode( $article["title"] ),
			"post_modified"		=> $article["open_time"],
			// "post_modified_gmt"	=> $article["open_time"],
			"comment_count"		=> $article["comment_count"],

			"post_parent"		=> 0,
			"menu_order"		=> 0,
			"post_type"			=> "post",

			"comment_status"	=> 'open',
			"ping_status"		=> 'open',
		];
		if( $wp_post_data ) {
			$affectedRows = $pdo
				->update( $import_article )
               	->table('wp_posts')
               	->where('ID', '=', $wp_post_data["ID"])
               	->execute();

		} else {
			$last_inserted_article_id = $pdo
				->insert( array_keys( $import_article ) )
                ->into('wp_posts')
                ->values( array_values( $import_article ) )
                ->execute(true);

			$wp_term_relationships = [
				"object_id"			=> $last_inserted_article_id,
				// ** TODO:カテゴリが複数ある場合は、マッピング処理を対応
				"term_taxonomy_id"	=> TERM_TAXONOMY_ID,
			];
			$last_inserted_relation_id = $pdo
				->insert( array_keys( $wp_term_relationships ) )
                ->into('wp_term_relationships')
                ->values( array_values( $wp_term_relationships ) )
                ->execute(true);

			// ** update category article count
	    	$wp_term_taxonomy = $pdo->select()
	                       ->from('wp_term_taxonomy')
	                       ->where( 'term_taxonomy_id', '=', TERM_TAXONOMY_ID)
	                       ->execute()->fetch();
           	if( $wp_term_taxonomy ) {
				$pdo
					->update([
						"count" => $wp_term_taxonomy["count"] + 1
					])
		           	->table('wp_term_taxonomy')
		           	->where('term_taxonomy_id', '=', TERM_TAXONOMY_ID)
		           	->execute();
	        }
	        // ** Comment
			if( isset( $article["comment"] ) ) {
				foreach ($article["comment"]["article"] as $article => $article_comment ) {
					$comment = [
						"comment_post_ID"	=> $last_inserted_article_id,
						"comment_author"	=> $article_comment["name"] ? $article_comment["name"] : '',
						"comment_date"		=> $article_comment["open_time"],
						"comment_content"	=> $article_comment["body"],
					];
					$pdo
						->insert( array_keys( $comment ) )
		                ->into('wp_comments')
		                ->values( array_values( $comment ) )
		                ->execute(true);
				}
			}
		}
	}
	$pdo->commit();
	// $pdo->rollBack();

	unset($pdo);

} catch (Exception $e) {
    var_dump($e);
	$pdo->rollBack();

    exit;
}
