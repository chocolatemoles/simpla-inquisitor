<?php
	if($_POST)
	{
		require_once('api/Simpla.php');
		
		class Inquisitor extends Simpla
		{
			function action()
			{
				$action = $this->request->post('action');
				$fix 	= $this->request->post('fix');

				switch($action)
				{
					// Images
					case 'images':
						$this->db->query('SELECT filename FROM __images');
						$db_images = $this->db->results('filename');

						// Original images
						$originals = array();
						
						if($dir = opendir('files/originals/'))
						{
							while(false !== ($file = readdir($dir)))
								if(!in_array($file, array('.', '..', '.htaccess')))
									$originals[$file] = $file;
								
							closedir($dir);
						}

						$lostOriginals = array();
						foreach(array_diff($originals, $db_images) as $filename)
						{
							if($fix == 'true')
								unlink(__DIR__ . '/files/originals/' . $filename);
							else
								$lostOriginals[] = $filename;
						}
				
						// Resized images
						$resizes = array();
						
						if($dir = opendir('files/products/'))
						{
							while(false !== ($file = readdir($dir)))
								if(!in_array($file, array('.', '..')))
								{
									$originalFilename = preg_replace('/\.\d*[x]\d*\w*\.?/', '.', $file);
									$resizes[$originalFilename] = $file;
								}
								
							closedir($dir);
						}
						
						$lostResizes = array();
						foreach(array_diff(array_keys($resizes), $db_images) as $filename)
						{
							if($fix == 'true')
								unlink(__DIR__ . '/files/products/' . $resizes[$filename]);
							else
								$lostResizes[] = $resizes[$filename];
						}

						// Output
						$output = array(
							'action'=>$action,
							'lostOriginals'=>$lostOriginals,
							'lostResizes'=>$lostResizes
						);
					break;
					
					case 'products':
						// Empty names
						$emptyNames = $this->checkEmpty('products', 'name');

						// Empty urls
						if($fix == 'true')
							$emptyUrls = $this->fixEmptyUrl('products');
						else
							$emptyUrls = $this->checkEmpty('products', 'url');
	
						// Urls has match
						if($fix == 'true')
							$urlsMatch = $this->fixMatchUrl('products');
						else
							$urlsMatch = $this->checkMatch('products', 'url');

						// Lost images
						$this->db->query('SELECT product_id, filename FROM __images');
						
						$images = array();
						
						if($dir = opendir('files/originals/'))
						{
							while(false !== ($file = readdir($dir)))
								if(!in_array($file, array('.', '..', '.htaccess')))
									$images[$file] = $file;
								
							closedir($dir);
						}

						$lostImages = array();
						foreach($this->db->results() as $result)
						{
							if(!isset($images[$result->filename]))
							{
								if($fix == 'true')
								{
									$query = $this->db->placehold('DELETE FROM __images WHERE product_id = ? AND filename = ?', $result->product_id, $result->filename);
									$this->db->query($query);
								}
								else
								{
									$lostImages[] = $result->product_id;
								}
							}
						}
						
						// Output
						$output = array(
							'emptyNames'=>$emptyNames,
							'emptyUrls'=>$emptyUrls,
							'urlsMatch'=>$urlsMatch,
							'lostImages'=>$lostImages
						);
					break;
					
					case 'categories':
					case 'brands':
					case 'pages':
					case 'blog':
						// Empty names
						$emptyNames = $this->checkEmpty($action, $action == 'pages' ? 'header' : 'name');

						// Empty urls
						if($fix == 'true' && $action != 'pages')
							$emptyUrls = $this->fixEmptyUrl($action);
						else
							$emptyUrls = $this->checkEmpty($action, 'url');
	
						// Urls has match
						if($fix == 'true' && $action != 'pages')
							$urlsMatch = $this->fixMatchUrl($action);
						else
							$urlsMatch = $this->checkMatch($action, 'url');
						
						// Output
						$output = array(
							'emptyNames'=>$emptyNames,
							'emptyUrls'=>$emptyUrls,
							'urlsMatch'=>$urlsMatch
						);
					break;
					
					default: 
						$output = array();
					break;
				}
				
				$this->output(array_merge(array('action'=>$action), $output));
			}
			
			function checkEmpty($table, $value)
			{
				$this->db->query("SELECT id FROM __$table WHERE $value = ''");
				
				$emptyValues = array();
				foreach($this->db->results('id') as $id)
					$emptyValues[] = $id;

				return $emptyValues;
			}

			function checkMatch($table, $value)
			{
				$this->db->query("SELECT p.id FROM __$table p INNER JOIN (SELECT $value FROM __$table WHERE $value != '' GROUP BY $value HAVING COUNT(id) > 1) d ON p.$value = d.$value");
				
				$matchValues = array();
				foreach($this->db->results('id') as $id)
					$matchValues[] = $id;
					
				return $matchValues;
			}
			
			function fixEmptyUrl($table)
			{
				$this->db->query("SELECT id, name FROM __$table WHERE url = ''");

				$results = array();
				foreach($this->db->results() as $result)
				{
					$url = $this->generateUrl($result->name);
					
					if(!empty($url))
					{
						$query = $this->db->placehold("UPDATE __$table SET url = '$url' WHERE id = ?", $result->id);
						$this->db->query($query);
					}
					else
					{
						$results[] = $result->id;
					}
				}

				return $results;
			}
			
			function fixMatchUrl($table)
			{
				$this->db->query("SELECT DISTINCT url FROM __$table");
				$existUrls = $this->db->results('url');
				$first = array();
				
				$this->db->query("SELECT t.id, t.url FROM __$table t INNER JOIN (SELECT url FROM __$table WHERE url != '' GROUP BY url HAVING COUNT(id) > 1) c ON t.url = c.url");

				foreach($this->db->results() as $result)
				{
					if(!isset($first[$result->url]))
					{
						$first[$result->url] = $result->url;
						continue;
					}

					$url = $result->url;
					$originalUrl = $url;
					$num = 1;
					
					while(in_array($url, $existUrls))
					{           
						$url = $originalUrl . '-' . $num;
						$num++;
					}

					$query = $this->db->placehold("UPDATE __$table SET url = '$url' WHERE id = ?", $result->id);
					$this->db->query($query);
					
					$existUrls[] = $url;
				}
				
				return array();
			}
			
			function generateUrl($name)
			{
				$translit = array(
					'А'=>'a', 'а'=>'a', 'Б'=>'b', 'б'=>'b', 'В'=>'v', 'в'=>'v', 'Ґ'=>'g', 'ґ'=>'g', 'Г'=>'g', 'г'=>'g', 
					'Д'=>'d', 'д'=>'d', 'Е'=>'e', 'е'=>'e', 'Ё'=>'e', 'ё'=>'e', 'Є'=>'e', 'є'=>'e', 'Ж'=>'d', 'ж'=>'d',
					'З'=>'z', 'з'=>'z', 'И'=>'i', 'и'=>'i', 'І'=>'i', 'і'=>'i', 'Ї'=>'i', 'ї'=>'i', 'Й'=>'i', 'й'=>'i',
					'К'=>'k', 'к'=>'k', 'Л'=>'l', 'л'=>'l', 'М'=>'m', 'м'=>'m', 'Н'=>'n', 'н'=>'n', 'О'=>'o', 'о'=>'o',
					'П'=>'p', 'п'=>'p', 'Р'=>'r', 'р'=>'r', 'С'=>'s', 'с'=>'s', 'Т'=>'t', 'т'=>'t', 'У'=>'u', 'у'=>'u',
					'Ф'=>'f', 'ф'=>'f', 'Х'=>'h', 'х'=>'h', 'Ц'=>'ts', 'ц'=>'ts', 'Ч'=>'ch', 'ч'=>'ch', 'Ш'=>'sh', 'ш'=>'sh',
					'Щ'=>'sch', 'щ'=>'sch', 'Ъ'=>'-', 'ъ'=>'-', 'Ы'=>'y', 'ы'=>'y', 'Ь'=>'-', 'ь'=>'-', 'Э'=>'e', 'э'=>'e',
					'Ю'=>'yu', 'ю'=>'yu', 'Я'=>'ya', 'я'=>'ya', ' '=>'-'
				);
				
				$url = strtr($name, $translit);
				$url = trim(preg_replace('/[^a-z0-9-]+/', '-', $url), " _-");
				$url = preg_replace('/\-+/', '-', $url);
				
				return $url;
			}
			
			function output($output)
			{
				header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
				
				print json_encode($output);
				exit;
			}
		}
		
		$inquisitor = new Inquisitor;
		$inquisitor->action();
		
		exit;
	}
?>
<!DOCTYPE HTML>
<html lang="ru-RU">
<head>
	<meta charset="UTF-8">
	<title>Simpla Inquisitor</title>
	
	<style type="text/css">
		body{
			background: #383f47;
			color: #cfe8eb;
			font-family: monospace;
			max-width: 600px;
			margin: 25px auto;
		}
		.container.loading:after {
			content: '';
			display: inline-block;
			width: 12px;
			height: 12px;
			border-radius: 50%;
			border: 2px solid #fff;
			border-color: #2196F3 transparent #2196F3 transparent;
			animation: loader 1.2s linear infinite;
			position: relative;
			top: 0;
			left: 15px;
		}
		@keyframes loader {
			0% {
				transform: rotate(0deg);
			}
			100% {
				transform: rotate(360deg);
			}
		}
		p{
			padding-left: 15px;
			padding-right: 15px;
			margin-top: 1em;
			margin-bottom: 1em
		}
		.title{
			background: #2c323a;
			padding-top: 10px;
			padding-bottom: 10px;
			border-radius: 5px
		}
		.subtitle{
			color: #eb75ff;
		}
		.line{
			padding-left: 30px
		}
		.no-errors{
			color: #8BC34A;
		}
		a{
			color: #8ec5ef;
			text-decoration: none;
		}
		.btn-fix-errors{
			background: linear-gradient(to bottom, #FF5722, #E91E63);
			display: block;
			padding: 10px;
			margin: 15px 0;
			text-align: center;
			color: #fff;
			border-radius: 5px;
			width: 100%;
			border: none;
			cursor: pointer;
			outline: none
		}
		.message-fix-errors{
			width: 100%;
			background: rgba(156, 39, 176, 0.25);
			padding: 15px;
			border-radius: 5px;
			box-sizing: border-box;
			text-align: center;
			margin: 15px 0;
			border: 2px solid #9C27B0;
		}
	</style>
</head>
<body>
	<div class="container loading"></div>
	
	<script type="text/javascript">
		'use strict';
		
		var $container = document.querySelector('.container'),
			steps = [
				{'images': 		'Изображения'},
				{'products': 	'Товары'},
				{'categories': 	'Категории'},
				{'brands': 		'Бренды'},
				{'pages': 		'Страницы'},
				{'blog': 		'Блог'}
			],
			step = 0,
			xhr = new XMLHttpRequest(),
			hasErrors = false,
			fixIt = false;

		function inspect()
		{
			$container.classList.add('loading');
			
			// Print title
			var $title = document.createElement('p');
				$title.className = 'title';
				$title.innerText = Object.values(steps[step])[0];
				
			$container.append($title);
			
			// Send request
			xhr.open('POST', window.location.pathname, true);
			xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
			xhr.onreadystatechange = function() 
			{
				if(xhr.readyState == 4)
					if(xhr.status == 200)
					{
						print(xhr.responseText);
						
						if(steps[step])
						{
							xhr.open('POST', window.location.pathname, true);

							inspect();
						}
						else
						{
							$container.classList.remove('loading');
							
							if(hasErrors)
							{
								if(fixIt)
								{
									var $message = document.createElement('p');
										$message.className = 'message-fix-errors';
										$message.innerText = 'Оставшиеся ошибки нужно исправить вручную';
										
									$container.append($message);
								}
								else
								{
									var $button = document.createElement('button');
										$button.className = 'btn-fix-errors';
										$button.innerText = 'Исправить ошибки';
										
									$container.append($button);
									
									$button.addEventListener('click', function(e){
										e.preventDefault();
										fixIt = true;
										step = 0;
										
										$container.innerHTML = '';
										
										inspect();
									}, false);
								}
							}
						}
					}
					else
						alert(xhr.status + ': ' + xhr.statusText);
			}

			setTimeout(function(){
				xhr.send('action=' + Object.keys(steps[step++])[0] + '&fix=' + fixIt);
			}, 500); // For print effect
		}
		
		function pushToResults(path, data)
		{
			var arr = [];
			
			data.forEach(function(el){
				arr.push(path + el);
			})
				
			return arr;
		}
		
		function print(data)
		{
			data = JSON.parse(data);

			var results = {};
				
			switch(data.action)
			{
				// Images
				case 'images':
					if(data.lostOriginals.length)
						results['Товара с этим изображением не существует'] = pushToResults('files/originals/', data.lostOriginals);

					if(data.lostResizes.length)
						if(results['Товара с этим изображением не существует'])
							results['Товара с этим изображением не существует'] = results['Товара с этим изображением не существует'].concat(pushToResults('files/products/', data.lostResizes));
						else
							results['Товара с этим изображением не существует'] = pushToResults('files/products/', data.lostResizes);

					break;
					
				// Products
				case 'products':
					if(data.emptyNames.length)
						results['Отсутсвует название'] = pushToResults('simpla/index.php?module=ProductAdmin&id=', data.emptyNames);
					
					if(data.emptyUrls.length)
						results['Отсутсвует url адрес'] = pushToResults('simpla/index.php?module=ProductAdmin&id=', data.emptyUrls);
					
					if(data.urlsMatch.length)
						results['Url адреса совпадают'] = pushToResults('simpla/index.php?module=ProductAdmin&id=', data.urlsMatch);
					
					if(data.lostImages.length)
						results['Отсутсвует файл с изображением'] = pushToResults('simpla/index.php?module=ProductAdmin&id=', data.lostImages);
					
					break;
					
				// Categories
				case 'categories':
					if(data.emptyNames.length)
						results['Отсутсвует название'] = pushToResults('simpla/index.php?module=CategoryAdmin&id=', data.emptyNames);
					
					if(data.emptyUrls.length)
						results['Отсутсвует url адрес'] = pushToResults('simpla/index.php?module=CategoryAdmin&id=', data.emptyUrls);
					
					if(data.urlsMatch.length)
						results['Url адреса совпадают'] = pushToResults('simpla/index.php?module=CategoryAdmin&id=', data.urlsMatch);
					
					break;
					
				// Brands
				case 'brands':
					if(data.emptyNames.length)
						results['Отсутсвует название'] = pushToResults('simpla/index.php?module=BrandAdmin&id=', data.emptyNames);
					
					if(data.emptyUrls.length)
						results['Отсутсвует url адрес'] = pushToResults('simpla/index.php?module=BrandAdmin&id=', data.emptyUrls);
					
					if(data.urlsMatch.length)
						results['Url адреса совпадают'] = pushToResults('simpla/index.php?module=BrandAdmin&id=', data.urlsMatch);
					
					break;
					
				// Pages
				case 'pages':
					if(data.emptyNames.length)
						results['Отсутсвует название'] = pushToResults('simpla/index.php?module=PageAdmin&id=', data.emptyNames);
					
					if(data.emptyUrls.length > 1)
						results['Отсутсвует url адрес'] = pushToResults('simpla/index.php?module=PageAdmin&id=', data.emptyUrls);
					
					if(data.urlsMatch.length)
						results['Url адреса совпадают'] = pushToResults('simpla/index.php?module=PageAdmin&id=', data.urlsMatch);
					
					break;
					
				// Blog
				case 'blog':
					if(data.emptyNames.length)
						results['Отсутсвует название'] = pushToResults('simpla/index.php?module=PostAdmin&id=', data.emptyNames);
					
					if(data.emptyUrls.length)
						results['Отсутсвует url адрес'] = pushToResults('simpla/index.php?module=PostAdmin&id=', data.emptyUrls);
					
					if(data.urlsMatch.length)
						results['Url адреса совпадают'] = pushToResults('simpla/index.php?module=PostAdmin&id=', data.urlsMatch);
					
					break;
			}

			if(Object.keys(results).length > 0)
			{
				// Print results
				for(var index in results) { 
					var $subtitle = document.createElement('p');
						$subtitle.className = 'subtitle';
						$subtitle.innerText = index;
						
					$container.append($subtitle);
					
					results[index].forEach(function(el){
						var $line = document.createElement('p');
							$line.className = 'line';
							
						if(data.action == 'images')
						{
							$line.innerText = el;
						}
						else
						{
							var $link = document.createElement('a');
								$link.setAttribute('href', el);
								$link.setAttribute('target', '_blank');
								$link.innerText = el;
								
							$line.append($link);
						}
						
						$container.append($line);
					})
				}
				
				hasErrors = true;
			}
			else
			{
				// Print 'no errors';
				var $noErrors = document.createElement('p');
					$noErrors.className = 'no-errors';
					$noErrors.innerText = 'Нет ошибок';
				
				$container.append($noErrors);
			}
		}
		
		inspect();
		
	</script>
</body>
</html>