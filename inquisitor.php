<?php
	if($_POST)
	{
		require_once('api/Simpla.php');
		
		class Inquisitor extends Simpla
		{
			function action()
			{
				$action = $this->request->post('action');
				
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
							$lostOriginals[] = $filename;
				
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
							$lostResizes[] = $resizes[$filename];

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
						$emptyUrls = $this->checkEmpty('products', 'url');
	
						// Urls has match
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
								$lostImages[] = $result->product_id;
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
						$emptyUrls = $this->checkEmpty($action, 'url');
	
						// Urls has match
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
				$this->db->query("SELECT id FROM __$table p INNER JOIN (SELECT $value FROM __$table WHERE $value != '' GROUP BY $value HAVING COUNT(id) > 1) d ON p.$value = d.$value");
				
				$matchValues = array();
				foreach($this->db->results('id') as $id)
					$matchValues[] = $id;
					
				return $matchValues;
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
		body.loading:after {
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
	</style>
</head>
<body class="loading">
	<script type="text/javascript">
		'use strict';
		
		var $body = document.querySelector('body'),
			steps = [
				{'images': 		'Изображения'},
				{'products': 	'Товары'},
				{'categories': 	'Категории'},
				{'brands': 		'Бренды'},
				{'pages': 		'Страницы'},
				{'blog': 		'Блог'}
			],
			step = 0,
			xhr = new XMLHttpRequest();
			
		function inspect()
		{
			// Print title
			var $title = document.createElement('p');
				$title.className = 'title';
				$title.innerText = Object.values(steps[step])[0];
				
			$body.append($title);
			
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
							$body.removeAttribute('class');
					}
					else
						alert(xhr.status + ': ' + xhr.statusText);
			}

			setTimeout(function(){
				xhr.send('action=' + Object.keys(steps[step++])[0]);
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
						
					$body.append($subtitle);
					
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
						
						$body.append($line);
					})
				}
			}
			else
			{
				// Print 'no errors';
				var $noErrors = document.createElement('p');
					$noErrors.className = 'no-errors';
					$noErrors.innerText = 'Нет ошибок';
				
				$body.append($noErrors);
			}
		}
		
		inspect();
		
	</script>
</body>
</html>