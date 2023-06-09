<?php

include "inc.php";
include "packet.php";
class zkClient{
	
    var $protocolVersion=0;
    var $lastZxid=0;
    //header
    var $xid=0; //    int32
    var $passWord="";
    var $st=false;
    var $sessionTimeoutMs=1000;//
    var $sessionId=0;
    //port
    var $port=0;
    var $addr="";
	var $errCode=0;
	var $errMsg='';
	var $isConnect=false;

	public function __construct($addr,$port=2181,$passWord=''){
		$this->addr=$addr;
		$this->port=$port;
		$this->passWord=$passWord;
	}

    function connect(){
		if($this->isConnect){
			return true;
		}
        $this->st = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->st === false) {
           return false;
        } 
        $result = socket_connect($this->st, $this->addr,  $this->port);
        if ($result === false) {
            $this->st=false;
            return false;
        }
        return $this->auth();
    }

    public function auth(){
        $packet = new zkPacket();
		/*
		connectRequest struct {
			ProtocolVersion int32
			LastZxidSeen    int64
			TimeOut         int32
			SessionID       int64
			Passwd          []byte
		}
		*/
        $packet->WriteInt($this->protocolVersion);
        $packet->WriteInt64($this->lastZxid);
        $packet->WriteInt($this->sessionTimeoutMs);
        $packet->WriteInt64($this->sessionId);
        if($this->passWord){
            $packet->WriteString($this->passWord);
        }else{
            //len
            $packet->WriteInt(16);
            //20 byte 0
            $packet->WriteInt64(0);
            $packet->WriteInt64(0);
        }
        //send
		$flag=$this->sendPack($packet,false);
		if($flag<1){
			return false;
		}
		//read len
		$recvPacket = $this->recvPack();
		/*
		type connectResponse struct {
				ProtocolVersion int32
				TimeOut         int32
				SessionID       int64
				Passwd          []byte
		}
		*/
		$ProtocolVersion= $recvPacket->ReadInt();
		$TimeOut= $recvPacket->ReadInt();
		$SessionID= $recvPacket->ReadInt64();
		$passwd= $recvPacket->ReadString();
		$this->isConnect=true;
        return $flag; 
    }

    public function get($path){
		/*
		type pathWatchRequest struct {
			Path  string
			Watch bool
		}*/
        $packet = new zkPacket();
        $this->xid++;
        $packet->WriteBegin($this->xid,opGetData);
        $packet->WriteString($path);
        $packet->WriteByte(0);
        //send
		$flag=$this->sendPack($packet);
        if($flag>0){
			$recvInfo = $this->recvPackByXid($this->xid);
			if(!$recvInfo||$recvInfo['err']!=0){
				$this->errMsg="error!";
				$this->err=$recvInfo['err'];
				return false;
			}
			/*
			type getDataResponse struct {
				Data []byte
				Stat Stat
			}*/
			return $recvInfo['recvPacket']->ReadString();
        }
        return false;
    }

	/*create */
	public function create($path,$data,$acl=array(['perms'=>PERM_ALL, 'scheme'=>"world", 'id'=>"anyone"]),$flags=0){
        if(!$path){
			$this->errMsg='path cannot be empty';
			return false;
		}
		if(!$data){
			$this->errMsg='data cannot be empty';
			return false;
		}
		foreach($acl as $z){
			if(!$z['perms']||!$z['scheme']||!$z['id']){
				$this->errMsg='acl parameter error';
				return false;
			}
		}
		
		$packet = new zkPacket();
        $this->xid++;
        $packet->WriteBegin($this->xid,opCreate);
		/*
		type CreateRequest struct {
			Path  string
			Data  []byte
			Acl   []ACL
			Flags int32
		}*/
        $packet->WriteString($path);
        $packet->WriteString($data);
		//Acl
		$packet->WriteInt(count($acl));
		foreach($acl as $z){
			$packet->WriteInt($z['perms']);
			$packet->WriteString($z['scheme']);
			$packet->WriteString($z['id']);
		}
		//Flags
		$packet->WriteInt($flags);
         //send
		$flag=$this->sendPack($packet);
        if($flag>0){
			$recvInfo = $this->recvPackByXid($this->xid);
			if(!$recvInfo||$recvInfo['err']!=0){
				$this->errMsg="error!";
				$this->err=$recvInfo['err'];
				return false;
			}
			/*
			type createResponse struct {
				Path string
			}*/
			return $recvInfo['recvPacket']->ReadString();
        }
        return false;
    }


	public function set($path,$data,$version=-1){
        if(!$path){
			$this->errMsg='path cannot be empty';
			return false;
		}
		if(!$data){
			$this->errMsg='data cannot be empty';
			return false;
		}
		$packet = new zkPacket();
        $this->xid++;
        $packet->WriteBegin($this->xid,opSetData);
		/*
		type SetDataRequest struct {
			Path    string
			Data    []byte
			Version int32
		}*/
        $packet->WriteString($path);
        $packet->WriteString($data);
		//version
		$packet->WriteInt($version);
        //send
		$flag=$this->sendPack($packet);
        if($flag>0){
			$recvInfo = $this->recvPackByXid($this->xid);
			if(!$recvInfo||$recvInfo['err']!=0){
				$this->errMsg="error!";
				$this->err=$recvInfo['err'];
				return false;
			}
			/*
			type setDataResponse  struct {
				Stat Stat
			}*/
			return true;
        }
        return false;
    }

	public function exists($path){
        if(!$path){
			$this->errMsg='path cannot be empty';
			return false;
		}
		/*
		type pathWatchRequest struct {
			Path  string
			Watch bool
		}*/
        $packet = new zkPacket();
        $this->xid++;
        $packet->WriteBegin($this->xid,opExists);
        $packet->WriteString($path);
        $packet->WriteByte(0);
		//send
		$flag=$this->sendPack($packet);
        if($flag>0){
			$recvInfo = $this->recvPackByXid($this->xid);
			if(!$recvInfo||$recvInfo['err']!=0){
				$this->errMsg="error!";
				$this->err=$recvInfo['err'];
				return false;
			}
			/*
			type statResponse   struct {
				Stat Stat
			}*/
			return true;
        }
        return false;
    }
	/*recv back*/
	private function recvPack(){
		$buf ="";
		socket_recv($this->st, $buf, 4, MSG_WAITALL);
		$len = unpack("N", $buf);
		socket_recv($this->st, $buf, intval($len[1]), MSG_WAITALL);
		$recvPacket = new zkPacket();
		$recvPacket->SetRecvPacketBuffer($buf,intval($len[1]));
		return $recvPacket;
	}

	/*recv back by xid*/
	private function recvPackByXid($xid,$retry=3){
		$i=0;
		while(true){
			$recvPacket=$this->recvPack();
			$resHeader=$recvPacket->ParsePacket();
			if ($resHeader['zxid'] > 0) {
				$this->lastZxid =$resHeader['zxid'];
			}
			if($resHeader['xid']==$xid){
				return ['err'=>$resHeader['err'],'recvPacket'=>$recvPacket];
			}
			$i++;
			if($i>$retry){
				break;
			}
		}
		return false;
	}

	/*send pack*/
	private function sendPack($sendPacket,$checkConnect=true){
		$sendPacket->WriteEnd();
		$request  = $sendPacket->GetPacketBuffer();
        $size=$sendPacket->GetPacketSize();
		if($checkConnect){
			if(!$this->connect()){
				return 0;
			}
		}
		//send
		return socket_write($this->st, $request,$size);
	}
}
