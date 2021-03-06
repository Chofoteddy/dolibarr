<?php
/* Copyright (C) 2004-2007 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2013 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005      Simon Tosser         <simon@kornog-computing.com>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@capnetworks.com>
 * Copyright (C) 2010	   Pierre Morin         <pierre.morin@auguria.net>
 * Copyright (C) 2010	   Juanjo Menent        <jmenent@2byte.es>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 */

/**
 *	\file       htdocs/document.php
 *  \brief      Wrapper to download data files
 *  \remarks    Call of this wrapper is made with URL:
 * 				document.php?modulepart=repfichierconcerne&file=relativepathoffile
 * 				document.php?modulepart=logs&file=dolibarr.log
 * 				document.php?modulepart=logs&hashp=sharekey
 */

define('NOTOKENRENEWAL',1); // Disables token renewal
// Pour autre que bittorrent, on charge environnement + info issus de logon (comme le user)
if (isset($_GET["modulepart"]) && $_GET["modulepart"] == 'bittorrent' && ! defined("NOLOGIN"))
{
	define("NOLOGIN",1);
	define("NOCSRFCHECK",1);	// We accept to go on this page from external web site.
}
if (! defined('NOREQUIREMENU')) define('NOREQUIREMENU','1');
if (! defined('NOREQUIREHTML')) define('NOREQUIREHTML','1');
if (! defined('NOREQUIREAJAX')) define('NOREQUIREAJAX','1');

/**
 * Header empty
 *
 * @return	void
 */
function llxHeader() { }
/**
 * Footer empty
 *
 * @return	void
 */
function llxFooter() { }


require 'main.inc.php';	// Load $user and permissions
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

$encoding = '';
$action=GETPOST('action','alpha');
$original_file=GETPOST('file','alpha');		// Do not use urldecode here ($_GET are already decoded by PHP).
$hashp=GETPOST('hashp','aZ09');
$modulepart=GETPOST('modulepart','alpha');
$urlsource=GETPOST('urlsource','alpha');
$entity=GETPOST('entity','int')?GETPOST('entity','int'):$conf->entity;

// Security check
if (empty($modulepart)) accessforbidden('Bad link. Bad value for parameter modulepart',0,0,1);
if (empty($original_file) && empty($hashp)) accessforbidden('Bad link. Missing identification to find file (original_file or hashp)',0,0,1);
if ($modulepart == 'fckeditor') $modulepart='medias';   // For backward compatibility

$socid=0;
if ($user->societe_id > 0) $socid = $user->societe_id;

// For some module part, dir may be privates
if (in_array($modulepart, array('facture_paiement','unpaid')))
{
	if (! $user->rights->societe->client->voir || $socid) $original_file='private/'.$user->id.'/'.$original_file;	// If user has no permission to see all, output dir is specific to user
}


/*
 * Action
 */

// None


/*
 * View
 */

// Define mime type
$type = 'application/octet-stream';
if (GETPOST('type','alpha')) $type=GETPOST('type','alpha');
else $type=dol_mimetype($original_file);

// Define attachment (attachment=true to force choice popup 'open'/'save as')
$attachment = true;
if (preg_match('/\.(html|htm)$/i',$original_file)) $attachment = false;
if (isset($_GET["attachment"])) $attachment = GETPOST("attachment",'alpha')?true:false;
if (! empty($conf->global->MAIN_DISABLE_FORCE_SAVEAS)) $attachment=false;

// If we have a hash public (hashp), we guess the original_file.
if (! empty($hashp))
{
	include_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
	$ecmfile=new EcmFiles($db);
	$result = $ecmfile->fetch(0, '', '', '', $hashp);
	if ($result > 0)
	{
		$tmp = explode('/', $ecmfile->filepath, 2);		// $ecmfile->filepatch is relative to document directory
		$moduleparttocheck = $tmp[0];
		if ($moduleparttocheck == $modulepart)
		{
			$original_file = (($tmp[1]?$tmp[1].'/':'').$ecmfile->filename);		// this is relative to module dir
			//var_dump($original_file); exit;
		}
		else
		{
			accessforbidden('Bad link. File owns to another module part.',0,0,1);
		}
	}
	else
	{
		accessforbidden('Bad link. File was not found or sharing attribute removed recently.',0,0,1);
	}
}


// Security: Delete string ../ into $original_file
$original_file = str_replace("../","/", $original_file);

// Find the subdirectory name as the reference
$refname=basename(dirname($original_file)."/");

// Security check
if (empty($modulepart)) accessforbidden('Bad value for parameter modulepart');
$check_access = dol_check_secure_access_document($modulepart, $original_file, $entity, $refname);
$accessallowed              = $check_access['accessallowed'];
$sqlprotectagainstexternals = $check_access['sqlprotectagainstexternals'];
$fullpath_original_file     = $check_access['original_file'];               // $fullpath_original_file is now a full path name

// Basic protection (against external users only)
if ($user->societe_id > 0)
{
	if ($sqlprotectagainstexternals)
	{
		$resql = $db->query($sqlprotectagainstexternals);
		if ($resql)
		{
			$num=$db->num_rows($resql);
			$i=0;
			while ($i < $num)
			{
				$obj = $db->fetch_object($resql);
				if ($user->societe_id != $obj->fk_soc)
				{
					$accessallowed=0;
					break;
				}
				$i++;
			}
		}
	}
}

// Security:
// Limit access if permissions are wrong
if (! $accessallowed)
{
	accessforbidden();
}

// Security:
// On interdit les remontees de repertoire ainsi que les pipe dans les noms de fichiers.
if (preg_match('/\.\./',$fullpath_original_file) || preg_match('/[<>|]/',$fullpath_original_file))
{
	dol_syslog("Refused to deliver file ".$fullpath_original_file);
	print "ErrorFileNameInvalid: ".$original_file;
	exit;
}


clearstatcache();

$filename = basename($fullpath_original_file);

// Output file on browser
dol_syslog("document.php download $fullpath_original_file filename=$filename content-type=$type");
$fullpath_original_file_osencoded=dol_osencode($fullpath_original_file);	// New file name encoded in OS encoding charset

// This test if file exists should be useless. We keep it to find bug more easily
if (! file_exists($fullpath_original_file_osencoded))
{
	dol_syslog("ErrorFileDoesNotExists: ".$fullpath_original_file);
	print "ErrorFileDoesNotExists: ".$original_file;
	exit;
}

// Permissions are ok and file found, so we return it
top_httphead($type);
header('Content-Description: File Transfer');
if ($encoding)   header('Content-Encoding: '.$encoding);
// Add MIME Content-Disposition from RFC 2183 (inline=automatically displayed, atachment=need user action to open)
if ($attachment) header('Content-Disposition: attachment; filename="'.$filename.'"');
else header('Content-Disposition: inline; filename="'.$filename.'"');
header('Content-Length: ' . dol_filesize($fullpath_original_file));
// Ajout directives pour resoudre bug IE
header('Cache-Control: Public, must-revalidate');
header('Pragma: public');

//ob_clean();
//flush();

readfile($fullpath_original_file_osencoded);

if (is_object($db)) $db->close();
