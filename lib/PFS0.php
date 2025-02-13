<?php

# Partial Implementation just to match our needs

include_once "AES.php";
include_once "NPDM.php";

class PFS0
{
    function __construct($fh, $dataOffset, $dataSize, $pfs0Offset, $pfs0Size)
    {
        $this->fh  = $fh;
		$this->dataOffset = $dataOffset;
		$this->dataSize = $dataSize; 
		$this->pfs0Offset = $pfs0Offset;
		$this->pfs0Size = $pfs0Size;
		$this->startOffset = $dataOffset+$pfs0Offset;
		fseek($this->fh, $dataOffset+ $pfs0Offset);
		$this->data = fread($this->fh,0x10);
		
    }

    function getHeader()
    {
        $this->pfs0header = substr($this->data, 0, 0x04);
        if ($this->pfs0header != "PFS0") {
            return false;
        }
        $this->numFiles = unpack("V", substr($this->data, 4, 0x04))[1];
		$this->stringTableSize = unpack("V", substr($this->data, 8, 0x04))[1];
		fseek($this->fh, $this->startOffset);
		
		$this->stringTableOffset = 0x10 + 0x18 * $this->numFiles;
        $this->fileBodyOffset = $this->stringTableOffset + $this->stringTableSize;
		$this->data = fread($this->fh,$this->fileBodyOffset);
        $this->stringTableOffset = 0x10 + 0x18 * $this->numFiles;
        $this->fileBodyOffset = $this->stringTableOffset + $this->stringTableSize;
		$this->filesList = [];
        for ($i = 0; $i < $this->numFiles; $i++) {
            $dataOffset = unpack("P", substr($this->data, 0x10 + (0x18 * $i), 0x08))[1];
            $dataSize = unpack("P", substr($this->data, 0x10+ 0x08 + (0x18 * $i), 0x08))[1];
            $stringOffset = unpack("V", substr($this->data, 0x10+0x08+0x08 + (0x18 * $i), 0x04))[1];
            $filename = "";
            $n = 0;
            while (true && $this->stringTableOffset + $stringOffset + $n < $this->dataSize-1) {
                $byte = unpack("C", substr($this->data, $this->stringTableOffset + $stringOffset + $n, 1))[1];
                if ($byte == 0x00) break;
                $filename = $filename . chr($byte);
                $n++;
            }
            $parts = explode('.', strtolower($filename));
			$file = new stdClass();
            $file->name = $filename;
            $file->size = $dataSize;
            $file->offset = $dataOffset;
			$this->filesList[] = $file;
        }
    }
	
	function extractFile($idx){
		fseek($this->fh, $this->startOffset+$this->fileBodyOffset+$this->filesList[$idx]->offset);
		
		$size = $this->filesList[$idx]->size;
		$chunksize = 5 * (1024 * 1024);
		header('Content-Type: application/octet-stream');
		header('Content-Transfer-Encoding: binary');
		header('Content-Length: '.$size);
		header('Content-Disposition: attachment;filename="'.$this->filesList[$idx]->name.'"');
		$tmpchunksize = $size;
		$tmpchunkdone = 0;
		while ($tmpchunksize>$chunksize)
		{ 
			$outdata =  fread($this->fh,$chunksize);
			print($outdata);
			$tmpchunksize -=$chunksize;
			$tmpchunkdone += 1;
				
			ob_flush();
			flush();
		}
		if($tmpchunksize<=$chunksize){
			$outdata = fread($this->fh,$tmpchunksize);
			print($outdata);	
			ob_flush();
			flush();
		}	
	}
}

# Test Class for decrypt on the fly contents and not store in memeory (make possibile to extract big files if needed) 

class PFS0Encrypted
{
    function __construct($fh, $encdataOffset, $encSize , $pfs0Offset, $pfs0Size,$key,$ctr)
    {
		$this->fh = $fh;
		$this->startctr = hex2bin($ctr);
		fseek($this->fh, $encdataOffset+ $pfs0Offset);
		$this->aesctr = new AESCTR(hex2bin(strtoupper($key)), hex2bin(strtoupper($ctr)), true);
		$this->data = $this->aesctr->decrypt(fread($this->fh,0x10),$this->getCTROffset($pfs0Offset));
		$this->startOffset = $encdataOffset+$pfs0Offset;
		$this->pfs0Offset = $pfs0Offset;
		$this->dataSize = $pfs0Size;
		$this->isexefs = false;
    }
	
#offset must be a multiple of 0x10
	function getCTROffset($offset){
		$ctr = new CTRCOUNTER_GMP($this->startctr);
		$adder = $offset/16;
		$ctr->add($adder);
		return $ctr->getCtr();
	}

    function getHeader()
    {
        $this->pfs0header = substr($this->data, 0, 0x04);
        if ($this->pfs0header != "PFS0") {
            return false;
        }
        $this->numFiles = unpack("V", substr($this->data, 4, 0x04))[1];
		$this->stringTableSize = unpack("V", substr($this->data, 8, 0x04))[1];
		fseek($this->fh, $this->startOffset);
        $this->stringTableOffset = 0x10 + 0x18 * $this->numFiles;
        $this->fileBodyOffset = $this->stringTableOffset + $this->stringTableSize;
		$this->data = $this->aesctr->decrypt(fread($this->fh,$this->fileBodyOffset),$this->getCTROffset($this->pfs0Offset));
		$this->filesList = [];
        for ($i = 0; $i < $this->numFiles; $i++) {
            $dataOffset = unpack("P", substr($this->data, 0x10 + (0x18 * $i), 0x08))[1];
            $dataSize = unpack("P", substr($this->data, 0x10+ 0x08 + (0x18 * $i), 0x08))[1];
            $stringOffset = unpack("V", substr($this->data, 0x10+0x08+0x08 + (0x18 * $i), 0x04))[1];
            $filename = "";
            $n = 0;
            while (true && $this->stringTableOffset + $stringOffset + $n < $this->dataSize-1) {
                $byte = unpack("C", substr($this->data, $this->stringTableOffset + $stringOffset + $n, 1))[1];
                if ($byte == 0x00) break;
                $filename = $filename . chr($byte);
                $n++;
            }
            $parts = explode('.', strtolower($filename));
			$file = new stdClass();
            $file->name = $filename;
            $file->size = $dataSize;
            $file->offset = $dataOffset;
			$this->filesList[] = $file;
			
            if ($parts[count($parts) - 1] == "cnmt") {
                $this->cnmt = new CNMT($this->getFile($i),$dataSize);
            }
			if ($parts[count($parts) - 1] == "npdm") {
				$this->isexefs = true;
				$this->npdm = new NPDM($this->getFile($i),$dataSize);
			}
        }
    }
	
# In memory extraction use on small file only
	function getFile($idx){
		$subber = ($this->fileBodyOffset+$this->filesList[$idx]->offset)%16;
		fseek($this->fh, $this->startOffset+$this->fileBodyOffset+$this->filesList[$idx]->offset-$subber);
		
		$decfile = $this->aesctr->decrypt(fread($this->fh,$this->filesList[$idx]->size+$subber),$this->getCTROffset($this->pfs0Offset+$this->fileBodyOffset+$this->filesList[$idx]->offset-$subber));
		$decfile = substr($decfile,$subber,$this->filesList[$idx]->size+$subber);
		return $decfile;
		
	}
	
	function extractFile($idx){
		$subber = ($this->fileBodyOffset+$this->filesList[$idx]->offset+$this->pfs0Offset)%16;
		$tmpchunksize = $size+$subber;
		fseek($this->fh, $this->startOffset+$this->fileBodyOffset+$this->filesList[$idx]->offset-$subber);
		
		$size = $this->filesList[$idx]->size;
		$chunksize = 5 * (1024 * 1024);
		header('Content-Type: application/octet-stream');
		header('Content-Transfer-Encoding: binary');
		header('Content-Length: '.$size);
		header('Content-Disposition: attachment;filename="'.$this->filesList[$idx]->name.'"');
		$tmpchunksize = $size+$subber;
		$tmpchunkdone = 0;
		while ($tmpchunksize>$chunksize)
		{ 
			$ctr = $this->getCTROffset($this->pfs0Offset+$this->fileBodyOffset+$this->filesList[$idx]->offset-$subber+($chunksize*$tmpchunkdone));
			$outdata =  $this->aesctr->decrypt(fread($this->fh,$chunksize),$ctr);
			if($tmpchunkdone == 0){
				print(substr($outdata,$subber));
			}else{
				print($outdata);
			}
            $tmpchunksize -=$chunksize;
			$tmpchunkdone += 1;
				
			ob_flush();
			flush();
		}
			
		if($tmpchunksize<=$chunksize){
			$ctr = $this->getCTROffset($this->pfs0Offset+$this->fileBodyOffset+$this->filesList[$idx]->offset-$subber+($chunksize*$tmpchunkdone));
			$outdata = $this->aesctr->decrypt(fread($this->fh,$tmpchunksize),$ctr);
			if($tmpchunkdone == 0){
				print(substr($outdata,$subber));
			}else{
				print($outdata);	
			}
			ob_flush();
			flush();
		}	
	}
}

# mediaType 0x80	Application (Base Game), 0x81 Patch Update , 0x82 AddOnContent (DLC)

class CNMT
{
    function __construct($data, $dataSize)
    {
        $data;
        $this->id = bin2hex(strrev(substr($data, 0, 0x8)));
        $this->version = unpack("V", (substr($data, 0x08, 0x4)))[1];
        $this->mediaType = substr($data, 0x0c, 0x1);
        $this->otherId = bin2hex(strrev(substr($data, 0x20, 0x08)));
        $this->reqsysversion = unpack("V", (substr($data, 0x28, 0x4)))[1];
    }
}