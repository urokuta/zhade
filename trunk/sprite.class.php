<?php
ob_end_flush();
error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
class sprite {
	var $spr, $sName, $sHeader, $sVersion, $numberFrames, $sSize;
	var $act, $aHeader, $aVersion, $aActions, $aAnimations, $aSubFrames, $aSize;
	var $frames, $aFrames, $aAnimData = array();
	var $palette = array();
	var $availableVersions = array('2.0','2.1'); // Ignore this, I dont use it, since I haven't found a sprite that doesn't have those versions
	
	function __construct($sprite='') {
		if(!empty($sprite))
		$this->readSprite($sprite);
	}
	
	private function readSprite($sprite) {
		// Open the file for reading only
		$this->spr = fopen($sprite, "rb");
		// Now lets grab the ident, which should be "SPR", the version number of palette images aswell as the number of 32bit images RGBA
		$this->sHeader = unpack('H4ident/Sversion/Snum_pal/Snum_rgba',fread($this->spr, 0x08));
		// Whats the filesize of the spr?
		$this->sSize = filesize($sprite);
		// Lets put the number of images into a seperate variable
		$this->numberFrames = $this->sHeader['num_pal']+$this->sHeader['num_rgba'];
		// Here we nicely format the version so it can be used as "2.0" "2.1" "2.3" etc. etc.
		$this->sVersion = dechex($this->sHeader['version']);
		$this->sVersion = preg_replace('{([0-9])(.*?)([0-9])}', '$1.$2', $this->sVersion);
		
		// If there are any palette images, let's go read out their data.
		// If the sprite has both, palette images AND rgba images, it the code wont be able to read the data correctly.
		// Simply cuz I haven't made it read both as I have never seen a sprite with BOTH before. Even actOR doesnt let you save an act file with both.
		if($this->sHeader['num_pal'] > 0) {
			// Let's loop through the palette images or better said frames
			for($i=0;$i<$this->sHeader['num_pal'];$i++) {
				// Grab the frame info: Width, Height and their Data Length (the length of the frames bitmap information)
				// I also add an index to the array called "offset" which stores the offset in the file at which the data of the frame starts
				$this->frames[$i] = unpack('Swidth/Sheight/Sdata_length', fread($this->spr, 0x06)) + array('offset'=>ftell($this->spr));
				// Let's skip all the data as we won't read it at this point. Thats why we set the offset above. So we can easily access it later
				// But we have to jump over it so that the next frames data can be loaded ;D
				fseek($this->spr, $this->frames[$i]['data_length'], SEEK_CUR);
			}
		// If there aren't any palette images, let's check for RGBA images
		} elseif($this->sHeader['num_rgba'] > 0) {
			// Loop through them
			for($i=0;$i<$this->sHeader['num_rgba'];$i++) {
				// Grab data: Width, Height
				$this->frames[$i] = unpack('Swidth/Sheight', fread($this->spr, 0x04));
				// The data length, we have to calculate it ourselves, cuz it isn't given =(
				// But we know, atleast I do, that 32bit sprites simply uses the .TGA file format. Well, uncompressed that is.
				// So we get the uncompressed data length simply through: width*height*4. Read TGA file format for further informations
				$this->frames[$i]['data_length'] = $this->frames[$i]['width'] * $this->frames[$i]['height'] * 4;
				// Add offset
				$this->frames[$i]['offset'] = ftell($this->spr);
				// Jump over the data for next frame
				fseek($this->spr, $this->frames[$i]['data_length'], SEEK_CUR);
			}
		}
		// After looping through all images we are at the point where the Palette is located =D
		// So lets store our offset this time called "PAL" so we know where it is ^^
		$this->frames['PAL'] = ftell($this->spr);
		$i=0;
		$a=0;
		// The pallete goes 'till the end so we just have to read it 'till the end of the file.
		// But normally the palette should be 1024 bytes long, if iam not mistaken
		while(!feof($this->spr)) {
			// Grab the color of the first entry in the palette
			$color = @unpack('Ccolor', fread($this->spr, 0x01));
			// Lol I forgot how the rest works but I can tell you what it does.
			// It stores the palette informations as such:
			// palette[hex]=RR:GG:BB
			if(dechex($a) < 100) {
				$this->palette[$a] .= $color['color'].":";
			}
			$i++;
			if($i >= 4) {
				$this->palette[$a] = rtrim($this->palette[$a],":");
				$a++;
				$i=0;
			}
		}
	}
	// Alright lets display the actual frame, shall we?
	// Here we will make use of the saved frame offsets and palette data =D
	public function displayFrame($frame=0, $getData=false) {
		// Once again, does the sprite has palette images, cuz then we will use the method to read that data, ignoring rgba frames
		if($this->sHeader['num_pal'] > 0) {		
		
			if($frame < 0) $frame = 0;//exit;
			$frame = preg_replace("{[^0-9]}","", $frame);
			
			// Does the given frame exceeds the max number of frames the spr has? kk
			if($frame > $this->numberFrames) exit;
			// Let's jump to the offset we saved while reading the sprite that corresponds to the frame number.
			fseek($this->spr, $this->frames[$frame]['offset']);
			// Lets make the image null just to be sure it hasnt been set before or smth
			$im = null;
			// Create a new image with the frames width and height
			$im = imagecreatetruecolor($this->frames[$frame]['width'],$this->frames[$frame]['height']);
			// This is the X-Axis (Width)
			$bg_length = 0;
			// The Y-Axis (Height)
			$bg_y = 0;
			// Now lets grab the frames bitmap informations =D
			for($i=0; $i<$this->frames[$frame]['data_length']; $i++) {
				// Now we grab the first pixels color index (which redirects us to the palette datas index)
				$color = unpack('H2', fread($this->spr, 0x01));
				// If the index is 00 (the first entry in the palette data which also indicates the transparency) See RLE-Compression
				if($color[1] == '00') {
					// We grab the length of this pixel. This is only needed on the first index (first index, not pixel). All others are always 1x1 pixels =P
					$length = unpack('C', fread($this->spr, 0x01));
				} else {
					// 1x1 pixel. >_>
					$length[1] = 1;
				}
				// Now we grab the corresponding color which we saved in the format (RR:GG:BB)
				$rgb = explode(":",$this->palette[hexdec($color[1])]);
				$bg_int = 1;
				// Loop through the length of the pixel and set the colors =D
				while($bg_int <= $length[1]) {
					
					// Make PHP do some color shit
					$bg_color = ImageColorAllocate($im,$rgb[0],$rgb[1],$rgb[2]);
					// If 00 (first index) make php do the color transparent
					if($color[1] == '00') ImageColorTransparent($im,$bg_color);
					// Paste it onto the image WOHOO pixels!
					ImageSetPixel($im,$bg_length,$bg_y,$bg_color);
					// Increase our position on the X-Axis, yep we go through the whole image pixel by pixel aint that cool?
					$bg_length++;
					// Do we exceed the frames width? No problem we go one up on the Y-Axis and reset the X-Axis to 0 =D
					if($bg_length >= $this->frames[$frame]['width']) {
						$bg_length = 0;
						$bg_y++;
					}
					// Next color width, normally its just 1 and ends here, on first index though it can higher
					$bg_int++;
				}
				
			}
			
			if ($getData)
				return $im;
			// Do some shiet to display the frame as .GIF
			header("Cache-Control: no-cache");
			header('Content-type: image/png');
			imagepng($im);
			ImageDestroy($im);
			// Alright, so it isn't a normal palette image sprite thingy but instead uses 32bit images, no problem dude
		} elseif($this->sHeader['num_rgba'] > 0) {
			// I cool and created whole new function just for that
			return $this->displayRGBAFrame($frame, $getData);
		}
	}
	// Screw old palette depending images, RGBA images are the way to go
	public function displayRGBAFrame($frame, $getData = false) {
		// same crap as before
		$frame = preg_replace("{[^0-9]}","", $frame);
		if($frame == 0) $frame = 1;
		if(!$frame) exit;
		if($frame > $this->numberFrames) exit;
		$frame = $frame-1;
		fseek($this->spr, $this->frames[$frame]['offset']);
		
		// Creating the image with width and height etc.
		$im = null;
		$im = imagecreatetruecolor($this->frames[$frame]['width'],$this->frames[$frame]['height']);
		// Make make we can do some transparency stuff
		imagesavealpha($im, true);
		// Alright, so we have to fill the whole thing in order to make the transparency work. Took me some time to figure this out
		imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
		// The Y-Axis, also see how its set to the height of the frame? Thats because we will be writing the image from top to bottom this time
		$bg_y = $this->frames[$frame]['height'];
		// X-Axis
		$bg_length = 0;
		// Loop through the bitmap data
		for($i=0; ftell($this->spr)<($this->frames[$frame]['offset']+$this->frames[$frame]['data_length']); $i++) {
			// No more palette crap, colors are saved right in the data (AABBGGRR)
			$pixel = unpack('Calpha/Cblue/Cgreen/Cred', fread($this->spr, 0x04));
			// The stored transparency goes from 0 to 255, we don't want that. Our transparency only goes from 0 to 127. So lets fix it.
			$pixel['alpha'] = round(($pixel['alpha'] * 127) / 255);
			$pixel['alpha'] = (127 - $pixel['alpha']);
			// Create the color, this time with 0% to 100% opacity, ain't that cool?
			$bg_color = imagecolorallocatealpha($im, $pixel['red'], $pixel['green'], $pixel['blue'], $pixel['alpha']);
			// Set the pixel
			imagesetpixel($im, $bg_length, $bg_y, $bg_color);
			// Increase x-Axis and y-Axis if neccessarry etc.
			$bg_length++;
			if($bg_length >= $this->frames[$frame]['width']) {
				$bg_length = 0;
				$bg_y--;
			}
		}

		//part not tested !!!
		if ($getData)
			return $im;

		// Alright now let's display our beauty. We have to use .PNG cuz of the uber transparency.
		header("Cache-Control: no-cache");
		header("Content-type: image/png");
		imagepng($im);
		ImageDestroy($im);
	}
	// So you want to see each frames at once?
	// Just make sure you have the php file to show the images.
	public function displayAllFrames() {
		for($i=1;$i<=$this->numberFrames;$i++) {
			echo '<img src="image.php?frame='.$i.'" />';
		}
	}
	// Let's display our palette, if anyone wants to see it =P
	public function displayPalette() {
		echo "<table cellspacing=\"1\" border=\"0\">\n";
		echo "<tr>";
		foreach($this->palette as $hex=>$color) {
			$color = explode(":",$color);
			echo "<td class=\"pal\" style=\"background: ".$this->rgbhex($color[0],$color[1],$color[2]).";\">".$this->hex(dechex($hex))."</td>";
			$i++;
			if($i>=16) {
				echo "</tr>\n";
				$i=0;
				echo "<tr>";
			}
		}
		echo "</table>\n";
	}
	
	public function getTranspColor()
	{
		$color = $this->palette[0];
		$color = explode(":",$color);
		return array($color[0],$color[1],$color[2]);
		 
	}
	
	// Just keep it here alrite?
	private function rgbhex($red, $green, $blue) {
		return sprintf('#%02X%02X%02X', $red, $green, $blue);
	}
	// Same as above
	private function hex($hex) {
		if(strlen($hex) == 1) $hex = '0'.$hex;
		return strtoupper($hex);
	}
	// Got all your stuff done? Let's close the files then.
	public function close() {
		@fclose($this->spr);
		@fclose($this->act);
	}
	// This displays the sprites and frames information.
	// Such as the version, the number of palette/rgba images and the frames width,height,data offset and data length (well more like debug info stuff i used^^)
	public function displayFrameInfo() {
		echo '<table cellspacing="1" cellpadding="3">';
		echo '<tr><td><b>Version:</b></td><td colspan="4">'.$this->sVersion.'</td></tr>';
		echo '<tr><td><b>Palette Images:</b></td><td colspan="4">'.$this->sHeader['num_pal'].'</td></tr>';
		echo '<tr><td><b>RGBA Images:</b></td><td colspan="4">'.$this->sHeader['num_rgba'].'</td></tr>';
		echo '<tr><td colspan="6" style="height:25px"></td></tr>';
		echo '<tr><td><b>Frame</b></td><td><b>Width</b></td><td><b>Height</b></td><td><b>Offset</b></td><td><b>Data Length</b></td></tr>';
		foreach($this->frames as $frame=>$info) {
			echo '<tr><td>'.($frame+1).'</td><td>'.$info['width'].'</td><td>'.$info['height'].'</td><td>'.$info['offset'].'</td><td>'.$info['data_length'].'</td><tr>';
		}
		echo '</table>';
	}
	
	/* ACT FILE FORMAT */
	
	// Alright, alright. Now this gave me some time lolz
	// As far as I have tested this it works.
	// So far it only displays the act files informations because I really got no nerve to make animated gifs using the informations
	// Why you ask? Go read stuff about LZW-Compression (Lempel–Ziv–Welch) and do it yourself.
	public function readAct($act, $aff = false) {
		// Open the act file.
		$this->act = fopen($act, "rb");
		// Just like on the sprites we read some infos: ident=ACT, version, number of frames
		$this->aHeader = unpack('H4ident/Sversion/SnumFrames', fread($this->act, 0x06));
		// Get the filesize (used this for sql database etc.)
		$this->aSize = filesize($act);
		// The total amount of animations used
		$this->aAnimations = $this->aHeader['numFrames'];
		// The number of actions is the num of animations divided by 8.
		// So what are the "actions"? I just named them like this. They're basicly the directions you can look at. Just like in actOR.
		//  \ | /  }
		// -- o -- }-> 8 in total
		//  / | \  }
		$this->aActions = ($this->aAnimations / 8);
		// The version
		$this->aVersion = dechex($this->aHeader['version']);
		// Format the version nicely
		$this->aVersion = (double)preg_replace('{([0-9])(.*?)([0-9])}', '$1.$2', $this->aVersion);
		// Skip some useless data on one needs
		fseek($this->act, 0xA, SEEK_CUR);
		// We're currently at action 0
		$currentAction = 0;
		// Our index for the while loop
		$anim = 0;
		// Arrays we save our informations in
		$pointerMemoryANIM = array();
		$pointerMemoryNF = array();
		$pointerMemoryPAT = array();
		// Lets go through the animations, shall we?
		while($anim < $this->aAnimations) {
			// First how many Frames does the action have?
			// On a normal headgear this would be 3. Because when you are sitting downwards you can look down with your head, left and right. So 3 in total.
			// Ff you have an animated sprite its more obviously
			$this->aAnimData[$anim] = unpack('lnumFrames', fread($this->act, 0x04));
			// So lets loop through those Frames, cuz they all got individual informations for us
			for($nf = 0; $nf < $this->aAnimData[$anim]['numFrames']; $nf++) {
				// Skip useless data
				fseek($this->act, 0x20, SEEK_CUR);
				// And now we check how many subFrames this frame got. Or better said Patterns (like in actOR)
				// For example, if you have a monster sprite and some special effects as seperates sprite frames in the .spr file.
				// You'll have to paste them onto the stage right? Thats where the patters are being created. Like layers in photoshop.
				$this->aAnimData[$anim][$nf] = unpack('lnumSubFrames', fread($this->act, 0x04));
				// Loop through the patterns or layers for more info about them
				for($patNo = 0; $patNo < $this->aAnimData[$anim][$nf]['numSubFrames']; $patNo++) {
					// Grab OffsetX, OffsetY (I didnt go to the point where i would need to figure out the (0,0) coordinates to which this relates to)
					// Spr number (the sprite frame from the .spr file)
					// mirrored? red,green,blue,alpha
					$this->aAnimData[$anim][$nf][$patNo] = unpack('lxOffset/lyOffset/lsprNo/lmirrored/Cred/Cgreen/Cblue/Calpha', fread($this->act, 0x14));
					// Now there are different informations stored depending on the act version.
					// from 2.0 till 2.3 we grab XYScale
					if($this->aVersion >= 2.0 && $this->aVersion <= 2.3) $this->aAnimData[$anim][$nf][$patNo] += unpack('fxyScale', fread($this->act, 0x04));
					// If its 2.4 or higher we get XScale and YScale
					if($this->aVersion >= 2.4) $this->aAnimData[$anim][$nf][$patNo] += unpack('fxScale/fyScale', fread($this->act, 0x08));
					// Now the rotation and the sprType (32 bit or not)
					$this->aAnimData[$anim][$nf][$patNo] += unpack('lrotation/lsprType', fread($this->act, 0x08));
					// If its 2.5 or higher we also got the sprites Width and Height (we should have this info from the sprite itself already though, dont ask me why they put this there)
					if($this->aVersion >= 2.5) $this->aAnimData[$anim][$nf][$patNo] += unpack('lsprWidth/lsprHeight', fread($this->act, 0x08));
					
				}
				// The end of the Patterns/Layers on the Frame.
				// So lets see if it has some kind of sound attached to it? (To the frame)
				$this->aAnimData[$anim][$nf] += unpack('lsoundNo', fread($this->act, 0x04));
				// See if there are some extra info, and store them
				$extrainfo = unpack('lbool', fread($this->act, 0x04));
				if($extrainfo['bool'] == 1) {
					fseek($this->act, 0x04, SEEK_CUR);
					$this->aAnimData[$anim][$nf] += unpack('lextraX/lextraY', fread($this->act, 0x08));	
					
					fseek($this->act, 0x04, SEEK_CUR);
					}
			}
			// Increase our Action and loop through the next frames and patterns^^
			$anim++;
		}
		// After reading all infos we are at the end of the act file where the sound informations are stored.
		$this->aSoundData = unpack('lnumSounds', fread($this->act, 0x04));
		// It has some sounds?
		if($this->aSoundData['numSounds'] > 0) {
			// We loop through number of sounds to grab it.
			for($nSnd = 0; $nSnd < $this->aSoundData['numSounds']; $nSnd++) {
				// Now because the sound file is a string (directs to some .wav file in the grf), saved in hex and for our advantage always exactly 40 bytes long
				// we read the string as hex values and save it like that
				$this->aSoundData[$nSnd] = unpack('H80file', fread($this->act, 0x28));
			}
		}
		// Oh we also got the interval a frame is played at? That info is ours! >:D
		for($intv = 0; $intv < $this->aAnimations; $intv++) {
			$this->aAnimData[$intv] += unpack('fanimSpeed', fread($this->act, 0x04));
		}
		// Lets format our earlier saved hex string to a actual string with letters and not just numbers ^^
		// Some note: If there are sounds in the act (mostly monsters) there always appears to be a sound called "atk", just like that.
		// It doesnt exist in the grf and I have no idea how it is being handled. Maybe some description of the sounds?
		foreach($this->aSoundData as $id=>$string) {
			if((string)$id != 'numSounds')
			$this->aSoundData[$id] = str_replace(pack('H2','00'),"",(pack('H*', $string['file'])));
		}

		if ($aff)
		{
			// Lets display our results of the act file.
			echo '<pre>';
			echo print_r($this->aAnimData, true);
			echo print_r($this->aSoundData, true);
			echo '</pre>';
			// Close the file, its no longer needed. =(
		}
		
		fclose($this->act);
	}
	
	public function getActData()
	{
		return $this->aAnimData;
	}
	
	
	public function getPngData($frame)
	{
		return $this->displayFrame($frame, true);
	}
	

	//get all information needed
	public function getDataPrepared($fMajor, $fMinor, $fsub=0)
	{
		$im = $this->getPngData($this->aAnimData[$fMajor][$fMinor][$fsub]["sprNo"]);

		if ($this->aAnimData[$fMajor][$fMinor][$fsub]["mirrored"] == 1)
			$im = $this->mirror($im);
		
		$xIM = $this->aAnimData[$fMajor][$fMinor][$fsub]["xOffset"];
		$yIM = $this->aAnimData[$fMajor][$fMinor][$fsub]["yOffset"];

		$wim = imagesx($im);
		$him =	imagesy($im);
		
		if (isset($this->aAnimData[$fMajor][$fMinor][$fsub]["sprWidth"]))
		{
			$wim = $this->aAnimData[$fMajor][$fMinor][$fsub]["sprWidth"];
		}
		
		if (isset($this->aAnimData[$fMajor][$fMinor][$fsub]["sprHeight"]))
			$him = $this->aAnimData[$fMajor][$fMinor][$fsub]["sprHeight"];
		
		$exX = $exY = 0;
		if (isset($this->aAnimData[$fMajor][$fMinor]["extraX"]))
			$exX = $this->aAnimData[$fMajor][$fMinor]["extraX"];
		if (isset($this->aAnimData[$fMajor][$fMinor]["extraY"]))
			$exY = $this->aAnimData[$fMajor][$fMinor]["extraY"];
	
		return array($im, $wim, $him, $xIM, $yIM, $exX, $exY);
	}
	
	function mirror($im)
	{
		$nWidth = imagesx($im);
		$nHeight = imagesy($im);
		
		$transp_col = imagecolorat($im,0,0);
		
		$newImg = imagecreatetruecolor($nWidth, $nHeight);
		imagealphablending($newImg, false);
		imagesavealpha($newImg,true);
		ImageColorTransparent($newImg, $transp_col);
		$transparent = imagecolorallocatealpha($newImg, $transp_col[0], $transp_col[1], $transp_col[2], 127);
		imagefilledrectangle($newImg, 0, 0, $nWidth, $nHeight, $transp_col);

		 for($i = 0;$i < $nWidth; $i++)
		 {
		  for($j = 0;$j < $nHeight; $j++)
		  {
		   $ref = imagecolorat($im,$i,$j);
		   imagesetpixel($newImg,$nWidth - $i,$j,$ref);
		  }
		 }
		
		return $newImg;
	}
}
?>