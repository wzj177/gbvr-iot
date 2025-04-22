let SDKModule;

const VIDEO_FRAME_SUB_TYPE_I = 0;					//I帧
const VIDEO_FRAME_SUB_TYPE_P = 1;					//P帧
const VIDEO_FRAME_SUB_TYPE_B = 2;					//B帧
const VIDEO_FRAME_SUB_TYPE_SMART_I = 18;			//智能I帧
const VIDEO_FRAME_SUB_TYPE_SMART_P = 19;			//智能P帧
const VIDEO_FRAME_SUB_TYPE_SMART_I_NORENDER = 20;	//智能I帧，但不显示

const ENCODE_TYPE_VIDEO_HI_H264 = 2;	//海思H.264编码格式
const ENCODE_TYPE_VIDEO_MY_H264 = 4;	//公司H.264编码格式
const ENCODE_TYPE_VIDEO_STD_H264 = 8;	//标准H.264编码格式
const ENCODE_TYPE_VIDEO_H265 = 12;		//H.265编码格式

const DATA_RECORD_MP4 = 5;				//录制MP4格式

const SP_STREAM_TYPE_DHSTD = 8;			//dav编码格式
const STREAM_TYPE_SVC = 13;				//H.264 SVC编码格式
const SP_STREAM_TYPE_FLV = 18;			//flv编码格式

const ENCRYPT_UNKOWN = 0;				//未知加密类型
const ENCRYPT_AES = 1;					//AES加密类型，16进制数组格式
const ENCRYPT_AES256 = 2;				//AES256加密类型，16进制数组格式
const ENCRYPT_AES_STRING_FORMAT = 3;	//AES加密类型，字符串格式

const CACHE_MODE_OFF = 0;				//关闭实时流自适应缓冲模式
const ADAPTIVE_CACHE = 1;				//自适应缓冲
const REALTIME_FIRST = 2;				//实时优先
const FLUENCY_FIRST = 3;				//流畅优先 
const FRAME_SCENE_POINTS_INFOR_SIZE = 144;          // 景物点信息单组信息长度
/* IVS类型 */
var IVS_TYPE =
{
	IVSINFOTYPE_PRESETPOS			: 1,						
	IVSINFOTYPE_MOTINTRKS			: 2,				
	IVSINFOTYPE_MOTINTRKS_EX		: 3,			
	IVSINFOTYPE_LIGHT				: 4,   // 光照		
	IVSINFOTYPE_RAWDATA				: 5,   // jason数据
	IVSINFOTYPE_TRACK				: 6,   // 智能分析信息 
	IVSINFOTYPE_TRACK_EX_B0			: 7,   // 智能结构化数据信息
	IVSINFOTYPE_MOTIONFRAME			: 9,	
	IVSINFOTYPE_VIDEO_CONCENTRATION : 10,
	IVSINFOTYPE_OVERLAY_PIC			: 11,	// 叠加图片帧
	IVSINFOTYPE_OSD_INFO			: 12,	// OSD辅助帧
	IVSINFOTYPE_GPS_INFO			: 13,   // GPS辅助帧
	IVSINFOTYPE_TAGGING_INFO		: 14,   // 景物点信息标注帧，辅助帧(0x13)
	IVSINFOTYPE_TRACK_A1			: 15,   // NVR浓缩信息轨迹点
	IVSINFOTYPE_DATA_WITH_LARGE_AMOUNT : 16,
    IVSINFOTYPE_TRACK_A1_EX         : 17,   // NVR浓缩信息轨迹点(扩展)
	IVSINFOTYPE_DATA_WITH_WATER_LEVEL_MONITOR : 18, //水位检测水位尺信息帧(0x17)
	IVSINFOTYPE_INTELFLOW			: 19,   // 智能客流量
	IVSINFOTYPE_DATA_WITH_SOUND_DECIBEL : 20,		//声音警报分贝值信息帧(0x18)
	IVSINFOTYPE_DATA_WITH_SMART_MOTION : 21,		//智能动检信息帧(0x19)
	IVSINFOTYPE_DHOP_SMART			: 22,			//开放平台智能帧(0x14)
	IVSINFOTYPE_TRAFFIC_LIGHT		: 23,			//交通信号灯(红绿灯)辅助帧(0x1D)
	IVSINFOTYPE_PTZ_LOCATION		: 24,       	//云台位置帧(0x21)
};

var DRAW_TYPE = 
{
	DRAW_JSON : 0,
	DRAW_TRACK : 1,
	DRAW_ALARM : 2,
	DRAW_RULE : 3,
	DRAW_MOVE_CHECK : 7,
	DRAW_TEST : 9,
	DRAW_WEB_RULE : 11,
	DRAW_WEB_ALARM : 12,
	DRAW_FLOW_INFO : 13,
	DRAW_TRACKEX2 : 14,
	DRAW_WUXI235_TRACKEX2 : 15,
	DRAW_TRACKEXA1 : 16,
	DRAW_TRACKEX2_TYPE_HUMAN : 17,
	DRAW_TRACKEX2_TYPE_VEHICLE : 18,
	DRAW_TRACKEX2_TYPE_NONMOTOR : 19,
	DRAW_TRACKEX2_TYPE_SHOPPRESENCE : 20, 
	DRAW_TRACKEX2_TYPE_FLOWBUSINESS : 21,
	DRAW_INTELFLOW : 22,
	DRAW_SMARTMOTION : 23,
	DRAW_DHOPSMART : 24,
	DRAW_DATA_WITH_LARGE_AMOUNT : 25,//大数据量帧
	DRAW_TRACKEX2_TYPE_BAG : 26,
	DRAW_RULE_HIGHWAY_LANES : 27,			//高速车道线
	DRAW_WATER_LEVEL_MONITOR : 28,			//水位尺
	DRAW_END : 29
};

//DHOP元素类型
var IVS_DHOP_ElEMENT_TYPE = 
{
	EM_DHOP_CIRCLE : 1,
	EM_DHOP_BrokenLine : 2,
	EM_DHOP_POLYGON : 3,
	EM_DHOP_TEXT : 4
};

importScripts('libplay.js');

addEventListener('message', receiveMessage, false);	

Module.onRuntimeInitialized = function(){
	m_bLoadSuccess = true;
	var msgType = 'LoadSuccess';
	sendMessage(m_nPlayPort, msgType, null);
}

var m_bPlayback = 0;
var m_bSupportH264MSE = false;
var m_bSupportH265MSE = false;
var m_nCanvasWidth = 1920;
var m_nCanvasHeight = 1080;
var m_nPlayPort = 0;

var jsInputData = null;
var jsInputDataAry = null;

var jsFrameBuf = null;
var jsFrameInfo = null;
let dataView = null;

var jsBuf = null;
var jsFrameBodyData = null;

var jsBufY = null;
var jsBufU = null;
var jsBufV = null;
var jsYuvDataY = null;
var jsYuvDataU = null;
var jsYuvDataV = null;

var jsRecordFrameBuf = null;
var	jsRecordFrameInfo = null;
var	dataViewRecord = null;

let ivsBuf = null;
let ivsDataArray = null;
let ivsDataView = null;

var m_nWidth = 0;
var m_nHeight = 0;
var m_nPreWidth = 0;
var m_nPreHeight = 0;

var m_bSmartEncode = 0;
var m_nVideoEncodeType = 0;

var m_bLoadSuccess = false;

var m_bDecryptionResult = false;

var m_nTotalStreamLength = 0;

function receiveMessage(event) 
{
	if (!m_bLoadSuccess)
	{
		return;
	}
	var message = event.data;
	switch (message.nType) 
	{
		//初始化
        case 'Init':
			m_bPlayback = message.option.bPlayback;
			m_bSupportH264MSE = message.option.bSupportH264MSE;
			m_bSupportH265MSE = message.option.bSupportH265MSE;
			m_nCanvasWidth = message.option.nCanvasWidth;
			m_nCanvasHeight = message.option.nCanvasHeight;
			Init();
            break;
        //送流
		case 'InputData':
			InputData(message.pData);
            break;
		//打开智能绘制
		case 'OpenIVSDraw':
			OpenIVSDraw();
            break;
		//关闭智能绘制
		case 'CloseIVSDraw':
			CloseIVSDraw();
            break;
		//开始码流录制
		case 'StartRecord':
			StartRecord(message.nRecordType);
            break;
		//停止码流录制
		case 'StopRecord':
			StopRecord();
            break;
		//设置播放速度
		case 'SetPlaySpeed':
			SetPlaySpeed(message.nSpeed);
            break;
		//设置OSD叠加
		case 'SetYUVOSDInfoEx':
			SetYUVOSDInfoEx(message.OSDInfo);
			break;
		case 'GetOriginalKeyCallBack':
			GetOriginalKey(message.playToken, message.playTokenKey, message.deviceID);
            break;
		case 'SetWebSecurityKey':
			SetWebSecurityKey(message.nDecryptType, message.nFrameType, message.strKey, message.stStreamInfo);
            break;
		//设置解密秘钥
		case 'SetSecurityKey':
			SetSecurityKey(message.nEncryptType, message.szKey, message.nKeyLen, message.szKeyId, message.nKeyIdLen);
            break;
		//设置是否支持硬解码标记
		case 'SetSupportWebMSE':
			m_bSupportH264MSE = message.bSupportH264MSE;
			m_bSupportH265MSE = message.bSupportH265MSE;
			SetSupportWebMSE(m_bSupportH264MSE, m_bSupportH265MSE);
		//暂停播放
		case 'Pause':
			Pause(message.bPause);
            break;
		//抓图
		case 'CatchPic':
			CatchPic();
            break;
		//停止播放
		case 'Stop':
			Stop();
            break;
		case 'setPrintLogLevel':
			SetPrintLogLevel(message.nLogLevel);
			break;
        default:
            break;
    }
}

function Init() 
{
	//获取PlaySDK空闲端口号
	var jsPort = Module._malloc(1);
	var jsPortAry = new Uint8Array(Module.HEAPU8.buffer, jsPort, 1);
	Module._PLAY_GetFreePort(jsPortAry.byteOffset);
	m_nPlayPort = jsPortAry[0];
	jsPortAry = null;
	Module._free(jsPort);
	
	//设置播放窗口宽高属性
	Module._PLAY_ViewResolutionChanged(m_nPlayPort, m_nCanvasWidth, m_nCanvasHeight, 0);
	
	//设置实时流模式
	var nRet = Module._PLAY_SetStreamOpenMode(m_nPlayPort, m_bPlayback);
	nRet = Module._PLAY_OpenStream(m_nPlayPort, 0, 0, 10*1024*1024);
	
	nRet = Module._PLAY_SetSupportWebMSE(m_nPlayPort, m_bSupportH264MSE, m_bSupportH265MSE);
	
	nRet = Module._PLAY_Play(m_nPlayPort, 1);
	if (nRet)
	{
		//向C++层申请一块wasm内存，用于接收码流
		jsInputData = Module._malloc(5*1024*1024);
		jsInputDataAry = new Uint8Array(Module.HEAPU8.buffer, jsInputData, 5*1024*1024);
		
		var msgType = 'InitSuccess';
		sendMessage(m_nPlayPort, msgType, null);
	}
}

function InputData(data) 
{
	m_nTotalStreamLength += data.length;
	
	if(jsInputDataAry) 
	{
		jsInputDataAry.set(data);
		var nRet = Module._PLAY_InputData(m_nPlayPort, jsInputDataAry.byteOffset, data.length); 
		var i = 0;
	}
}

function OpenIVSDraw() 
{
	Module._PLAY_RenderPrivateData(m_nPlayPort, 1, 0);
}

function CloseIVSDraw() 
{
	Module._PLAY_RenderPrivateData(m_nPlayPort, 0, 0);
}

function StartRecord(nRecordType) 
{
	Module._PLAY_StartDataRecord(m_nPlayPort, 0, nRecordType);
}

function StopRecord() 
{
	Module._PLAY_StopDataRecord(m_nPlayPort);
}

function SetPlaySpeed(nSpeed)
{
	Module._PLAY_SetPlaySpeed(m_nPlayPort, nSpeed);
}

function SetYUVOSDInfoEx(OSDInfo)
{
	if (0 == m_nCanvasWidth || 0 == m_nCanvasHeight)
	{
		return;
	}
	
	let nOsdCount = OSDInfo.osdCount;
	let nStuOsdInfoSize = 18376;//单个YUV_OSD_INFO_EX结构体18376字节
	const OsdDataInfoPtr = Module._malloc(nStuOsdInfoSize);
	let arrayFormat = new Uint8Array(Module.HEAPU8.buffer);
	for (let i = 0; i < nStuOsdInfoSize; i ++)
	{
		arrayFormat[OsdDataInfoPtr + i] = 0;
	}
	
	Module.HEAPU8[OsdDataInfoPtr + 0] = 1;//是否是私有字体 
	let strFontPath = "Font.bin";
	let nSequence = 0;
	strFontPath.split('').forEach((char, nIndex) => 
	{
		let nValue = char.charCodeAt(0);
		arrayFormat[OsdDataInfoPtr + nSequence + 1] = nValue;
		nSequence++;
	});
	
	Module.HEAP32[OsdDataInfoPtr/4 + 65] = nOsdCount;
	for (let i = 0; i < nOsdCount; i ++)
	{
		let nPointX = OSDInfo.osdList[i].pointX / m_nCanvasWidth * m_nWidth;
		let nPointY = OSDInfo.osdList[i].pointY / m_nCanvasHeight * m_nHeight;
		
		Module.HEAP32[OsdDataInfoPtr/4 + 66 + 564/4 * i] = nPointX;// 旋转点像素x位置
		Module.HEAP32[OsdDataInfoPtr/4 + 67 + 564/4 * i] = nPointY;// 旋转点像素y位置
		Module.HEAP32[OsdDataInfoPtr/4 + 68 + 564/4 * i] = OSDInfo.osdList[i].colorR;// osd叠加颜色r，范围0-255
		Module.HEAP32[OsdDataInfoPtr/4 + 69 + 564/4 * i] = OSDInfo.osdList[i].colorG;// osd叠加颜色g，范围0-255
		Module.HEAP32[OsdDataInfoPtr/4 + 70 + 564/4 * i] = OSDInfo.osdList[i].colorB;// osd叠加颜色b，范围0-255
		Module.HEAP32[OsdDataInfoPtr/4 + 71 + 564/4 * i] = OSDInfo.osdList[i].colorA;// osd叠加透明度，范围0-255
		
		nSequence = 0;
		//JS默认采用UTF-16编码，需要先转为UTF-8编码
		let UTF8Array = UTF16ToUTF8(OSDInfo.osdList[i].strOsdData);
		for(let j = 0; j < UTF8Array.length; j++)
		{
			arrayFormat[(OsdDataInfoPtr + nSequence + 288 + 564 * i) >> 0] = UTF8Array[j];// osd数据，使用utf-8编码
			nSequence++;
		}
		
		Module.HEAP32[OsdDataInfoPtr/4 + 200 + 564/4 * i] = OSDInfo.osdList[i].fontX;// 字体宽度, 使用私有字体时无效
		Module.HEAP32[OsdDataInfoPtr/4 + 201 + 564/4 * i] = OSDInfo.osdList[i].fontY;// 字体高度, 最大512
		Module.HEAP32[OsdDataInfoPtr/4 + 202 + 564/4 * i] = OSDInfo.osdList[i].rotateAngle;// 旋转角度，范围0-359度
		Module.HEAPU8[OsdDataInfoPtr + 812 + 564 * i] = OSDInfo.osdList[i].coordinate8192;// 8192坐标系, 0表示像素坐标x,y基于码流分辨率, 1表示基于8192坐标系	
	}
	Module._PLAY_SetYUVOSDInfoEx(m_nPlayPort, OsdDataInfoPtr);
	
	Module._free(OsdDataInfoPtr);
}

function GetOriginalKey(playToken, playTokenKey, deviceID)
{
	var arrPlayToken = Module.intArrayFromString(playToken).concat(0);//add '\0'
	var playTokenPtr = Module._malloc(arrPlayToken.length);//采用声明的c函数 _malloc
	Module.HEAPU8.set(arrPlayToken, playTokenPtr);//复制字符串内容
	
	var arrPlayTokenKey = Module.intArrayFromString(playTokenKey).concat(0);
	var playTokenKeyPtr = Module._malloc(arrPlayTokenKey.length);
	Module.HEAPU8.set(arrPlayTokenKey, playTokenKeyPtr);
	
	var arrDeviceID = Module.intArrayFromString(deviceID).concat(0); 
	var deviceIDPtr = Module._malloc(arrDeviceID.length);  
	Module.HEAPU8.set(arrDeviceID, deviceIDPtr); 

	var outKeyPtr = Module._malloc(256);
	var outKeyLengthPtr = Module._malloc(4);	

	var nRet = Module._PLAY_GetOriginalKey(m_nPlayPort, playTokenPtr, playTokenKeyPtr, deviceIDPtr, outKeyPtr, outKeyLengthPtr);
	
	var outKeyLength = Module.HEAP32[outKeyLengthPtr >>2];
	var outKeyTmp = "";
	
	if ((1 == nRet) && (outKeyLength <= 256))
	{
		var jsKeyBuf = new ArrayBuffer(outKeyLength);
		var jsKeyData = new Uint8Array(jsKeyBuf);
		jsKeyData.set(Module.HEAPU8.subarray(outKeyPtr, outKeyPtr + outKeyLength));
		outKeyTmp = ArrayBufferToString(jsKeyBuf);
	}
	
	Module._free(playTokenPtr);//释放内存
	Module._free(playTokenKeyPtr);
	Module._free(deviceIDPtr);
	Module._free(outKeyPtr);
	Module._free(outKeyLengthPtr);
	
	var outKeyParam = 
	{
		nRet: nRet, 
		outKey: outKeyTmp,
	};
	
	var msgType = 'GetOriginalKeyCallBack';
	var msgData =  
	{	
		nRet: nRet,
		outKey: outKeyTmp,
	}
	
	sendMessage(m_nPlayPort, msgType, msgData);
}

function SetWebSecurityKey(nDecryptType, nFrameType, strKey, stStreamInfo)
{
	var arrStrKey = Module.intArrayFromString(strKey).concat(0);
	var strKeyPtr = Module._malloc(arrStrKey.length);
	Module.HEAPU8.set(arrStrKey, strKeyPtr);	
	
	var arrSdpInfo = Module.intArrayFromString(stStreamInfo.sdpInfo).concat(0); 
	var sdpInfoPtr = Module._malloc(arrSdpInfo.length);  
	Module.HEAPU8.set(arrSdpInfo, sdpInfoPtr);  

	var arrUserName = Module.intArrayFromString(stStreamInfo.strUserName).concat(0); 
	var userNamePtr = Module._malloc(arrUserName.length);  
	Module.HEAPU8.set(arrUserName, userNamePtr); 		

	var arrPassWord = Module.intArrayFromString(stStreamInfo.strPassWord).concat(0); 
	var passWordPtr = Module._malloc(arrPassWord.length);  
	Module.HEAPU8.set(arrPassWord, passWordPtr);   

	const stStreamInfoPtr = Module._malloc(16);//4:uint32的字节大小  	
	Module.HEAP32[stStreamInfoPtr/4 + 0] = sdpInfoPtr; 
    Module.HEAP32[stStreamInfoPtr/4 + 1] = userNamePtr;  
    Module.HEAP32[stStreamInfoPtr/4 + 2] = passWordPtr; 
    Module.HEAP32[stStreamInfoPtr/4 + 3] = stStreamInfo.nSsrc; 
	
	Module._PLAY_SetWebSecurityKey(m_nPlayPort, nDecryptType, nFrameType, strKeyPtr, stStreamInfoPtr);
	
	Module._free(strKeyPtr);//释放内存
	Module._free(sdpInfoPtr);
	Module._free(userNamePtr);
	Module._free(passWordPtr);
	Module._free(stStreamInfoPtr);
}

function SetSecurityKey(nEncryptType, szKey, nKeyLen, szKeyId, nKeyIdLen)
{
	var nRet = 1;
	
	var strKey = Module._malloc(49);
	var arrayKey = new Uint8Array(Module.HEAPU8.buffer);
	var nSequence = 0;
	
	if (ENCRYPT_AES == nEncryptType)
	{
		//逐字节的形式去内存中设置值，直接修改wasm内存数据
		szKey.forEach((value, nIndex) => {
			arrayKey[(strKey + nSequence) >> 0] = value;
			nSequence++;
		});
	}
	else if(ENCRYPT_AES256 == nEncryptType)
	{
		var szKeyIdTmp = new Uint8Array(16); ;

		//协议规定ENCRYPT_AES256对应的枚举值为1
		arrayKey[(strKey + nSequence) >> 0] = 1;
		nSequence++;
		if (0 == nKeyIdLen)
		{
			for(var i = 0; i < 16; i++)
			{
				szKeyIdTmp[i] = 0x00;
			}
			nKeyIdLen = 16;
			szKeyId = szKeyIdTmp;
		}
		
		//拼接上key ID，逐字节的形式去内存中设置值，直接修改wasm内存数据
		szKeyId.forEach((value, nIndex) => {
			arrayKey[(strKey + nSequence) >> 0] = value;
			nSequence++;
		});
		
		//拼接上key，逐字节的形式去内存中设置值，直接修改wasm内存数据
		szKey.forEach((value, nIndex) => {
			arrayKey[strKey + nSequence] = value;
			nSequence++;
		});
		
		nKeyLen = 1 + nKeyLen + nKeyIdLen;
		szKeyIdTmp = null;
	}
	else if(ENCRYPT_AES_STRING_FORMAT == nEncryptType)
	{
		szKey.split('').forEach((char, nIndex) => {
			arrayKey[(strKey + nSequence) >> 0] = char.charCodeAt(0);
			nSequence++;
		});
	}
	nRet = Module._PLAY_SetSecurityKey(m_nPlayPort, strKey, nKeyLen);
	
	Module._free(strKey);
	
	return nRet;
}

function SetSupportWebMSE(bSupportH264MSE, bSupportH265MSE)
{
	Module._PLAY_SetSupportWebMSE(m_nPlayPort, bSupportH264MSE, bSupportH265MSE);
}

function Pause(bPause) 
{
	Module._PLAY_Pause(m_nPlayPort, bPause);
}

function CatchPic()
{
	var nSize = m_nWidth * m_nHeight * 3/2;
	var pJpegBuf = Module._malloc(nSize);
	var pJpegBufArr = new Uint8Array(Module.HEAPU8.buffer, pJpegBuf, nSize);
	
	var pJpegSize = Module._malloc(4);
	var pJpegSizeArr = new Uint8Array(Module.HEAPU8.buffer, pJpegSize, 4);
	
	//获取当前图像编码后的jpeg图片数据
	Module._PLAY_GetPicJPEG(m_nPlayPort, pJpegBufArr.byteOffset, nSize, pJpegSizeArr.byteOffset, 100);
	
	//C++内存数据拷贝至JS内存
	var nDataSize = (pJpegSizeArr[3] << 24) + (pJpegSizeArr[2] << 16) + (pJpegSizeArr[1] << 8) + pJpegSizeArr[0];
	var pOutJpegBuf = new ArrayBuffer(nDataSize);
	var pOutJpegBufArr = new Uint8Array(pOutJpegBuf);
	pOutJpegBufArr.set(Module.HEAPU8.subarray(pJpegBufArr.byteOffset, pJpegBufArr.byteOffset + nDataSize));
	
	var msgType = 'CatchPicCallBack';
	var msgData =  
	{	
		buffer: pOutJpegBufArr
	}
	sendMessage(m_nPlayPort, msgType, msgData);
	
	Module._free(pJpegBuf);
	Module._free(pJpegSize);
	pJpegBufArr = null;
	pJpegSizeArr = null;
	pOutJpegBuf= null;
	pOutJpegBufArr = null;
	
}

function Stop() 
{
	var nRet = Module._PLAY_Stop(m_nPlayPort);
	if (0 == nRet)
	{
		return;
	}
	nRet = Module._PLAY_CloseStream(m_nPlayPort); 
	
	jsInputDataAry = null;
	Module._free(jsInputData);
	
	jsFrameBuf = null;
	jsFrameInfo = null;
	dataView = null;
	
	jsBufY = null;
	jsBufU = null;
	jsBufV = null;
	jsYuvDataY = null;
	jsYuvDataU = null;
	jsYuvDataV = null;
	
	jsRecordFrameBuf = null;
	jsRecordFrameInfo = null;
	dataViewRecord = null;
	
	m_nTotalStreamLength = 0;
}

function SetPrintLogLevel(nLogLevel)
{
	Module._PLAY_SetPrintLogLevel(nLogLevel);
}

function cPlusVisibleDecCallBack(nPort, pBufY, pBufU, pBufV, nSize, pFrameInfo)
{
	var stuFrameInfo = {};
	
	stuFrameInfo.nTotalStreamLength = m_nTotalStreamLength;
	
	if(!jsFrameInfo) 
	{
		jsFrameBuf = new ArrayBuffer(292);//通过二进制对象分配一块连续内存
		jsFrameInfo = new Uint8Array(jsFrameBuf);//二进制对象绑定到视图，通过视图对内存进行读写操作
		dataView = new DataView(jsFrameBuf);
	}
	jsFrameInfo.set(Module.HEAPU8.subarray(pFrameInfo, pFrameInfo + 292));//c中的内存拷贝到刚分配的js内存中
	//帧类型
	stuFrameInfo.nFrameType = dataView.getInt32(0, true);
	//帧序号
	stuFrameInfo.nFrameID = dataView.getInt32(4, true);
	//帧子类型
	stuFrameInfo.nFrameSubType = dataView.getInt32(56, true);
	
	//帧时间
	stuFrameInfo.nYear = dataView.getUint16(40, true);
	stuFrameInfo.nMonth = dataView.getUint16(42, true);
	stuFrameInfo.nDay = dataView.getUint16(46, true);
	stuFrameInfo.nHour = dataView.getUint16(48, true);
	stuFrameInfo.nMinute = dataView.getUint16(50, true);
	stuFrameInfo.nSecond = dataView.getUint16(52, true);
	
	var msgData =  {};
	//视频
	if (1 == stuFrameInfo.nFrameType)
	{	
		//剩余缓冲数据量
		stuFrameInfo.nRemainData = dataView.getInt32(36, true);
		//抽帧标记
		stuFrameInfo.bThrowFrame = dataView.getUint8(120, true);
		if (0 == stuFrameInfo.bThrowFrame)
		{
			//编码类型
			stuFrameInfo.nEncodeType = dataView.getInt32(108, true);
			//码流类型
			stuFrameInfo.nStreamType = dataView.getInt32(112, true);
			//时间戳
			stuFrameInfo.nTimeStamp = dataView.getUint32(8, true);
			//图像宽度
			stuFrameInfo.nWidth = dataView.getInt32(12, true);
			m_nWidth = stuFrameInfo.nWidth;
			//图像高度
			stuFrameInfo.nHeight = dataView.getInt32(16, true);
			m_nHeight = stuFrameInfo.nHeight;
			//视频帧率
			stuFrameInfo.nFrameRate = dataView.getInt32(20, true);
			//图像跨距
			stuFrameInfo.nStride = dataView.getInt32(116, true);
			//vui句法中视频三原色
			stuFrameInfo.nColorPrimaries = dataView.getUint8(124, true);
			//vui句法中视频信号转换函数
			stuFrameInfo.nColorTransfer = dataView.getUint8(125, true);
			//vui中视频颜色空间
			stuFrameInfo.nColorSpace = dataView.getUint8(126, true);
			//颜色范围 0:yuv范围16-235,对应 TV; 1:yuv范围0-255, 对应 PC
			stuFrameInfo.bColorFull = dataView.getUint8(127, true);
			
			if ((ENCODE_TYPE_VIDEO_HI_H264 == stuFrameInfo.nEncodeType) || (ENCODE_TYPE_VIDEO_MY_H264 == stuFrameInfo.nEncodeType) || (ENCODE_TYPE_VIDEO_STD_H264 == stuFrameInfo.nEncodeType))
			{
				//H.264编码类型
				m_nVideoEncodeType = 1;
			}
			else if(12 == stuFrameInfo.nEncodeType)
			{
				//H.265编码类型
				m_nVideoEncodeType = 2;
			}
			
			//智能I/P帧
			if ((VIDEO_FRAME_SUB_TYPE_SMART_I == stuFrameInfo.nFrameSubType) 
				|| (VIDEO_FRAME_SUB_TYPE_SMART_P == stuFrameInfo.nFrameSubType)
				|| (VIDEO_FRAME_SUB_TYPE_SMART_I_NORENDER == stuFrameInfo.nFrameSubType))
			{
				//Smart H.264或者Smart H.265
				m_bSmartEncode = 1;
			}
			else if(0 == stuFrameInfo.nFrameSubType)
			{
				m_bSmartEncode = 0;
			}
			
			//SVC码流不支持硬解码
			if ((((1 == m_nVideoEncodeType) && (true == m_bSupportH264MSE)) 
				|| ((2 == m_nVideoEncodeType) && (true == m_bSupportH265MSE))) 
				&& (STREAM_TYPE_SVC != stuFrameInfo.nStreamType))
			{
				//读取码流裸数据
				jsBuf = new ArrayBuffer(nSize);//通过二进制对象分配一块连续内存
				jsFrameBodyData = new Uint8Array(jsBuf);//二进制对象绑定到视图，通过视图对内存进行读写操作
				jsFrameBodyData.set(Module.HEAPU8.subarray(pBufY, pBufY + nSize));//c中的内存拷贝到刚分配的js内存中
				
				msgData =  
				{	
					pBufY: jsFrameBodyData, 
					pBufU: null,
					pBufV: null, 
					nSize: nSize,
					stuFrameInfo: stuFrameInfo,
				}
			}
			else
			{
				if((0 == pBufY) || (0 == pBufU) || (0 == pBufV))
				{
					return;
				}
				
				if (m_nWidth != m_nPreWidth || m_nHeight != m_nPreHeight)
				{
					m_nPreWidth = m_nWidth;
					m_nPreHeight = m_nHeight;
					
					jsBufY = null;
					jsBufU = null;
					jsBufV = null;
					jsYuvDataY = null;
					jsYuvDataU = null;
					jsYuvDataV = null;
					
					jsBufY = new ArrayBuffer(m_nWidth * m_nHeight);//通过二进制对象分配一块连续内存
					jsYuvDataY = new Uint8Array(jsBufY);//二进制对象绑定到视图，通过视图对内存进行读写操作
						
					jsBufU = new ArrayBuffer(m_nWidth * m_nHeight / 4);
					jsYuvDataU = new Uint8Array(jsBufU);
						
					jsBufV = new ArrayBuffer(m_nWidth * m_nHeight / 4);
					jsYuvDataV = new Uint8Array(jsBufV);
				}
				
				var h = 0;
				//将C++层YUV解码数据Y分量数据拷贝至JS层内存中	
				for(h = 0; h < stuFrameInfo.nHeight; h++)
				{
					jsYuvDataY.set(Module.HEAPU8.subarray((pBufY + h * stuFrameInfo.nStride), (pBufY + h * stuFrameInfo.nStride) + stuFrameInfo.nWidth), h * stuFrameInfo.nWidth);//c中的内存拷贝到刚分配的js内存中
				}
				//将C++层YUV解码数据U分量数据拷贝至JS层内存中		
				for(h = 0; h < stuFrameInfo.nHeight / 2; h++)
				{
					jsYuvDataU.set(Module.HEAPU8.subarray((pBufU + h * stuFrameInfo.nStride/2), (pBufU + h * stuFrameInfo.nStride/2) + stuFrameInfo.nWidth/2), h * stuFrameInfo.nWidth/2);//c中的内存拷贝到刚分配的js内存中
				}
				//将C++层YUV解码数据V分量数据拷贝至JS层内存中		
				for(h = 0; h < stuFrameInfo.nHeight / 2; h++)
				{
					jsYuvDataV.set(Module.HEAPU8.subarray((pBufV + h * stuFrameInfo.nStride/2), (pBufV + h * stuFrameInfo.nStride/2) + stuFrameInfo.nWidth/2), h * stuFrameInfo.nWidth/2);//c中的内存拷贝到刚分配的js内存中
				}
				
				msgData =  
				{	
					pBufY: jsYuvDataY,
					pBufU: jsYuvDataU,
					pBufV: jsYuvDataV, 
					nSize: nSize,
					stuFrameInfo: stuFrameInfo,
				}
			}
		}
		else
		{
			msgData =  
			{	
				pBufY: null,
				pBufU: null,
				pBufV: null, 
				nSize: 0,
				stuFrameInfo: stuFrameInfo,
			}
		}
	}
	else if(2 == stuFrameInfo.nFrameType)//音频帧
	{
		//总通道数
		stuFrameInfo.nTotalChannel = dataView.getInt32(68, true);
		//当前通道
		stuFrameInfo.nCurChannel = dataView.getInt32(72, true);
		//暂不支持双通道音频播放
		if (stuFrameInfo.nCurChannel > 0)
		{
			return;
		}
		//采样位数
		stuFrameInfo.nBits = dataView.getInt32(28, true);
		//采样率
		stuFrameInfo.nSamples = dataView.getInt32(32, true);
		//声道数
		stuFrameInfo.nAudioChnNum = dataView.getInt32(24, true);
		
		var AudioBuf = new ArrayBuffer(nSize);
		var UI8AudioData = new Uint8Array(AudioBuf);
		//将C++层解码后的pcm音频数据拷贝至JS层内存
        UI8AudioData.set(Module.HEAPU8.subarray(pBufY, pBufY + nSize));
		
		msgData =  
		{	
			pBufY: UI8AudioData,
			pBufU: null,
			pBufV: null, 
			nSize: nSize,
			stuFrameInfo: stuFrameInfo,
		}
	}
	
	var msgType = 'VisibleDecCallBack';
	
	sendMessage(nPort, msgType, msgData);
	
	jsBuf = null;
	jsFrameBodyData = null;
}

/*
 * C++层AES解密回调。
 *
 * @param[in] nPort 端口号
 * @param[in] nFrameID 视频帧序号
 * @param[in] bSuccess 是否解密成功
 */
function cDigitalSignCallBack(nPort, nFrameID, bSuccess)
{
	m_bDecryptionResult = bSuccess;
	var msgType = 'DecryptionResultCallBack';
	var msgData =  
	{	
		bSuccess: bSuccess
	}
	
	sendMessage(nPort, msgType, msgData);
}

/*
 * C++层码流录制回调，回调至JS层进行数据存储
 *
 * @param[in] nPort 端口号
 * @param[in] pData 码流数据
 * @param[in] nDataLen 数据长度
 * @param[in] nOffset 偏移量
 * @param[in] pFrameInfo 码流信息
 */
function cRecordDataCallBack(nPort, pData, nDataLen, nOffset, pFrameInfo) 
{
	var stuFrameInfo = {};
	if (!jsRecordFrameInfo) 
	{
		jsRecordFrameBuf = new ArrayBuffer(292); //通过二进制对象分配一块连续内存
		jsRecordFrameInfo = new Uint8Array(jsRecordFrameBuf); //二进制对象绑定到视图，通过视图对内存进行读写操作
		dataViewRecord = new DataView(jsRecordFrameBuf);
	}
	jsRecordFrameInfo.set(Module.HEAPU8.subarray(pFrameInfo, pFrameInfo + 292)); //c中的内存拷贝到刚分配的js内存中
	
	//帧类型
	stuFrameInfo.nFrameType = dataViewRecord.getInt32(0, true);
	//帧序号
	stuFrameInfo.nFrameID = dataViewRecord.getInt32(4, true);
	//帧子类型
	stuFrameInfo.nFrameSubType = dataViewRecord.getInt32(56, true);
	
	//视频帧
	if (1 == stuFrameInfo.nFrameType) 
	{
		//编码类型
		stuFrameInfo.nEncodeType = dataViewRecord.getInt32(68, true);
		//码流类型
		stuFrameInfo.nStreamType = dataViewRecord.getInt32(72, true);
		
		//时间戳
		stuFrameInfo.nTimeStamp = dataViewRecord.getUint32(8, true);
		//帧时间
		stuFrameInfo.nYear = dataViewRecord.getUint16(40, true);
		stuFrameInfo.nMonth = dataViewRecord.getUint16(42, true);
		stuFrameInfo.nDay = dataViewRecord.getUint16(46, true);
		stuFrameInfo.nHour = dataViewRecord.getUint16(48, true);
		stuFrameInfo.nMinute = dataViewRecord.getUint16(50, true);
		stuFrameInfo.nSecond = dataViewRecord.getUint16(52, true);
	}
	
	var bufRecord = new ArrayBuffer(nDataLen);
	var arrayRecord = new Uint8Array(bufRecord);  
	arrayRecord.set(Module.HEAPU8.subarray(pData, pData + nDataLen));
	
	var msgType = 'RecordDataCallBack';
	var msgData =  
	{	
		pRecordData: arrayRecord,
		nLen: nDataLen,
		Offset: nOffset, 
		stuFrameInfo: stuFrameInfo,
	}
	sendMessage(nPort, msgType, msgData);
	
	bufRecord = null;
	arrayRecord = null;
}

function cIVSDrawDataCallBack(nPort, pBuf, nType, nLen, nReallen)
{
	//帧序号为-1时不绘制
	if(-1 == nReallen)
	{
		return;
	}
	
	var pParseredBuf = null;
	
	ivsBuf = new ArrayBuffer(nLen);//通过二进制对象分配一块连续内存
	ivsDataArray = new Uint8Array(ivsBuf);//二进制对象绑定到视图，通过视图对内存进行读写操作
	ivsDataArray.set(Module.HEAPU8.subarray(pBuf, pBuf + nLen));
	ivsDataView = new DataView(ivsDataArray.buffer);
	
	if (IVS_TYPE.IVSINFOTYPE_INTELFLOW == nType)
	{//智能客流量
		var stuIntelflowInfo = {};
		stuIntelflowInfo.NumberStat = ivsDataView.getUint16(0, true);//大类业务方案
		stuIntelflowInfo.nIntelFlowPlanNum = ivsDataView.getUint16(2, true);//智能客流规则数量(最大不会超过32个)
		
		var pIntelFlowPlan = ivsDataView.getUint32(4, true);
		let IntelFlowPlanBuf = new ArrayBuffer(12);
		let IntelFlowPlanArray = new Uint8Array(IntelFlowPlanBuf);
		let IntelFlowPlanView = new DataView(IntelFlowPlanBuf);
		
		stuIntelflowInfo.pIntelFlowPlan = new Array(stuIntelflowInfo.nIntelFlowPlanNum);
		for (let i = 0; i < stuIntelflowInfo.nIntelFlowPlanNum; i++)
		{
			IntelFlowPlanArray.set(Module.HEAPU8.subarray(pIntelFlowPlan + i * 12, pIntelFlowPlan + i * 12 + 12));
			//解析智能客流规则
			stuIntelflowInfo.pIntelFlowPlan[i] = {};
			stuIntelflowInfo.pIntelFlowPlan[i].PlanId = IntelFlowPlanView.getUint16(0, true);//规则ID
			stuIntelflowInfo.pIntelFlowPlan[i].RuleType = IntelFlowPlanView.getUint16(2, true);//规则类型
			stuIntelflowInfo.pIntelFlowPlan[i].RegionNum = IntelFlowPlanView.getUint16(8, true);//区域数目
			var pRegion = IntelFlowPlanView.getUint32(4, true);
			let RegionBuf = new ArrayBuffer(12);
			let RegionArray = new Uint8Array(RegionBuf);
			let RegionView = new DataView(RegionBuf);
			
			stuIntelflowInfo.pIntelFlowPlan[i].pRegion = new Array(stuIntelflowInfo.pIntelFlowPlan[i].RegionNum);
			for (let j = 0; j < stuIntelflowInfo.pIntelFlowPlan[i].RegionNum; j++)
			{
				RegionArray.set(Module.HEAPU8.subarray(pRegion + j * 12, pRegion + j * 12 + 12));
				
				stuIntelflowInfo.pIntelFlowPlan[i].pRegion[j] = {};
				stuIntelflowInfo.pIntelFlowPlan[i].pRegion[j].RegionId = RegionView.getUint16(0, true);//区域ID
				stuIntelflowInfo.pIntelFlowPlan[i].pRegion[j].State = RegionView.getUint16(2, true);//状态:离开or进入
				stuIntelflowInfo.pIntelFlowPlan[i].pRegion[j].PeopleNum = RegionView.getUint32(4, true);//人数
			}
		}
		
		pParseredBuf = stuIntelflowInfo;
	}
	else if (IVS_TYPE.IVSINFOTYPE_DHOP_SMART == nType)
	{//DHOP开放平台智能帧
		var stuObjDHOP = {};
		stuObjDHOP.nId = ivsDataView.getUint32(0, true);//对象ID
		stuObjDHOP.wCustom = ivsDataView.getUint16(4, true);//自定义值
		stuObjDHOP.chState = ivsDataView.getUint8(6, true);//对象状态
		stuObjDHOP.chCount = ivsDataView.getUint8(7, true);//元素个数
		
		//解析DHOP元素
		var pElement = ivsDataView.getUint32(8, true);
		let elementBuf = new ArrayBuffer(12);
		let elementDataArray = new Uint8Array(elementBuf);
		let elementDataView = new DataView(elementBuf);
		
		stuObjDHOP.pElement = new Array(stuObjDHOP.chCount);
		for (var i = 0; i < stuObjDHOP.chCount; i++)
		{
			elementDataArray.set(Module.HEAPU8.subarray(pElement + i * 12, pElement + i * 12 + 12));
			//解析DHOP元素类型
			stuObjDHOP.pElement[i] = {};
			stuObjDHOP.pElement[i].nStructType = elementDataView.getUint32(0, true);
			stuObjDHOP.pElement[i].nStructLength = elementDataView.getUint32(4, true);
			var pStruct = elementDataView.getUint32(8, true);
			
			let structBuf = new ArrayBuffer(stuObjDHOP.pElement[i].nStructLength);
			let structArray = new Uint8Array(structBuf);
			let structDataView = new DataView(structBuf);
			structArray.set(Module.HEAPU8.subarray(pStruct, pStruct + stuObjDHOP.pElement[i].nStructLength));
			
			stuObjDHOP.pElement[i].pStruct = {};
			if (IVS_DHOP_ElEMENT_TYPE.EM_DHOP_CIRCLE == stuObjDHOP.pElement[i].nStructType)
			{
				stuObjDHOP.pElement[i].pStruct.chType = structDataView.getUint8(0, true);//子类型0x3
				stuObjDHOP.pElement[i].pStruct.chWidth = structDataView.getUint8(1, true);//线宽，单位px
				stuObjDHOP.pElement[i].pStruct.chStyle = structDataView.getUint8(2, true);//样式
				
				stuObjDHOP.pElement[i].pStruct.wRadius = structDataView.getUint16(4, true);//半径
				stuObjDHOP.pElement[i].pStruct.positionCircle = {};//圆心坐标
				stuObjDHOP.pElement[i].pStruct.positionCircle.x = structDataView.getUint16(8, true);
				stuObjDHOP.pElement[i].pStruct.positionCircle.y = structDataView.getUint16(10, true);
				
				stuObjDHOP.pElement[i].pStruct.chLineA = structDataView.getUint8(12, true);//边框线条颜色(ARGB)
				stuObjDHOP.pElement[i].pStruct.chLineR = structDataView.getUint8(13, true);
				stuObjDHOP.pElement[i].pStruct.chLineG = structDataView.getUint8(14, true);
				stuObjDHOP.pElement[i].pStruct.chLineB = structDataView.getUint8(15, true);
				stuObjDHOP.pElement[i].pStruct.chRegA = structDataView.getUint8(16, true);//区域填充颜色(ARGB)
				stuObjDHOP.pElement[i].pStruct.chRegR = structDataView.getUint8(17, true);
				stuObjDHOP.pElement[i].pStruct.chRegG = structDataView.getUint8(18, true);
				stuObjDHOP.pElement[i].pStruct.chRegB = structDataView.getUint8(19, true);
				
			}
			else if(IVS_DHOP_ElEMENT_TYPE.EM_DHOP_BrokenLine == stuObjDHOP.pElement[i].nStructType)
			{
				stuObjDHOP.pElement[i].pStruct.chType = structDataView.getUint8(0, true);//子类型0x2
				stuObjDHOP.pElement[i].pStruct.chCount = structDataView.getUint8(1, true);//端点个数
				stuObjDHOP.pElement[i].pStruct.chWidth = structDataView.getUint8(2, true);//线宽，单位px
				stuObjDHOP.pElement[i].pStruct.chStyle = structDataView.getUint8(3, true);//样式
				stuObjDHOP.pElement[i].pStruct.chLineA = structDataView.getUint8(4, true);//边框线条颜色(ARGB)
				stuObjDHOP.pElement[i].pStruct.chLineR = structDataView.getUint8(5, true);
				stuObjDHOP.pElement[i].pStruct.chLineG = structDataView.getUint8(6, true);
				stuObjDHOP.pElement[i].pStruct.chLineB = structDataView.getUint8(7, true);
				//端点坐标
				var pPoints = null;
				let pointsBuf = null;
				let pointsDataArray = null;
				let pointsDataView = null;
				
				if (stuObjDHOP.pElement[i].pStruct.chCount > 0)
				{
					stuObjDHOP.pElement[i].pStruct.pPoints = new Array(stuObjDHOP.pElement[i].pStruct.chCount);
					pPoints = structDataView.getUint32(8, true);
					pointsBuf = new ArrayBuffer(4);
					pointsDataArray = new Uint8Array(pointsBuf);
					pointsDataView = new DataView(pointsBuf);
				}
				
				for(var j = 0; j < stuObjDHOP.pElement[i].pStruct.chCount; j++)
				{
					pointsDataArray.set(Module.HEAPU8.subarray(pPoints + j*4, pPoints + j*4 + 4));
					stuObjDHOP.pElement[i].pStruct.pPoints[j] = {};
					stuObjDHOP.pElement[i].pStruct.pPoints[j].x = pointsDataView.getUint16(0, true);
					stuObjDHOP.pElement[i].pStruct.pPoints[j].y = pointsDataView.getUint16(2, true);
				}
			}
			else if(IVS_DHOP_ElEMENT_TYPE.EM_DHOP_POLYGON == stuObjDHOP.pElement[i].nStructType)
			{
				stuObjDHOP.pElement[i].pStruct.chType = structDataView.getUint8(0, true);//子类型0x3
				stuObjDHOP.pElement[i].pStruct.chCount = structDataView.getUint8(1, true);//端点个数
				stuObjDHOP.pElement[i].pStruct.chWidth = structDataView.getUint8(2, true);//线宽，单位px
				stuObjDHOP.pElement[i].pStruct.chStyle = structDataView.getUint8(3, true);//样式
				stuObjDHOP.pElement[i].pStruct.chLineA = structDataView.getUint8(4, true);//边框线条颜色(ARGB)
				stuObjDHOP.pElement[i].pStruct.chLineR = structDataView.getUint8(5, true);
				stuObjDHOP.pElement[i].pStruct.chLineG = structDataView.getUint8(6, true);
				stuObjDHOP.pElement[i].pStruct.chLineB = structDataView.getUint8(7, true);
				stuObjDHOP.pElement[i].pStruct.chRegA = structDataView.getUint8(8, true);//区域填充颜色(ARGB)
				stuObjDHOP.pElement[i].pStruct.chRegR = structDataView.getUint8(9, true);
				stuObjDHOP.pElement[i].pStruct.chRegG = structDataView.getUint8(10, true);
				stuObjDHOP.pElement[i].pStruct.chRegB = structDataView.getUint8(11, true);
				//端点坐标
				var pPoints = null;
				let pointsBuf = null;
				let pointsDataArray = null;
				let pointsDataView = null;
				
				if (stuObjDHOP.pElement[i].pStruct.chCount > 0)
				{
					stuObjDHOP.pElement[i].pStruct.pPoints = new Array(stuObjDHOP.pElement[i].pStruct.chCount);
					pPoints = structDataView.getUint32(12, true);
					pointsBuf = new ArrayBuffer(4);
					pointsDataArray = new Uint8Array(pointsBuf);
					pointsDataView = new DataView(pointsBuf);
				}
				
				for(var j = 0; j < stuObjDHOP.pElement[i].pStruct.chCount; j++)
				{
					pointsDataArray.set(Module.HEAPU8.subarray(pPoints + j*4, pPoints + j*4 + 4));
					stuObjDHOP.pElement[i].pStruct.pPoints[j] = {};
					stuObjDHOP.pElement[i].pStruct.pPoints[j].x = pointsDataView.getUint16(0, true);
					stuObjDHOP.pElement[i].pStruct.pPoints[j].y = pointsDataView.getUint16(2, true);
				}
			}
			else if(IVS_DHOP_ElEMENT_TYPE.EM_DHOP_TEXT == stuObjDHOP.pElement[i].nStructType)
			{
				stuObjDHOP.pElement[i].pStruct.chType = structDataView.getUint8(0, true);//子类型0x4
				stuObjDHOP.pElement[i].pStruct.chCharset = structDataView.getUint8(1, true);//编码方式
				stuObjDHOP.pElement[i].pStruct.stringPos = {};//字符坐标
				stuObjDHOP.pElement[i].pStruct.stringPos.x = structDataView.getUint16(4, true);
				stuObjDHOP.pElement[i].pStruct.stringPos.y = structDataView.getUint16(6, true);
				stuObjDHOP.pElement[i].pStruct.chLineA = structDataView.getUint8(8, true);//字体颜色(ARGB)
				stuObjDHOP.pElement[i].pStruct.chLineR = structDataView.getUint8(9, true);
				stuObjDHOP.pElement[i].pStruct.chLineG = structDataView.getUint8(10, true);
				stuObjDHOP.pElement[i].pStruct.chLineB = structDataView.getUint8(11, true);
				stuObjDHOP.pElement[i].pStruct.chFontSize = structDataView.getUint8(12, true);//字体大小，单位px
				stuObjDHOP.pElement[i].pStruct.chFontAlign = structDataView.getUint8(13, true);//对齐方式
				stuObjDHOP.pElement[i].pStruct.wTxtLen = structDataView.getUint16(14, true);//字符长度
				
				var pString = structDataView.getUint32(16, true);
				var stringBuf = new ArrayBuffer(stuObjDHOP.pElement[i].pStruct.wTxtLen);
				var stringDataArray = new Uint8Array(stringBuf);
				var stringDataView = new DataView(stringBuf);
				stringDataArray.set(Module.HEAPU8.subarray(pString, pString + stuObjDHOP.pElement[i].pStruct.wTxtLen));
				stuObjDHOP.pElement[i].pStruct.stringDataArray = stringDataArray;
			}
		}
		
		//解析DHOP信息内容
		stuObjDHOP.nInfoLen = ivsDataView.getUint16(12, true);//信息长度
		if (stuObjDHOP.nInfoLen > 0)
		{
			var pInfo = ivsDataView.getUint32(16, true);
			let infoBuf = new ArrayBuffer(stuObjDHOP.nInfoLen);
			let infoDataArray = new Uint8Array(infoBuf);
			infoDataArray.set(Module.HEAPU8.subarray(pInfo, pInfo + stuObjDHOP.nInfoLen));
			stuObjDHOP.pInfo = infoDataArray;
		}
		
		pParseredBuf = stuObjDHOP;
	}
	else if (IVS_TYPE.IVSINFOTYPE_TAGGING_INFO == nType)//景物点信息标注帧
	{
		let dataView = new DataView(ivsBuf); 

		let tagInfoNum = nLen/FRAME_SCENE_POINTS_INFOR_SIZE;

		let tagInfos = [];
		for (let tagInfoIndex = 0; tagInfoIndex < tagInfoNum; tagInfoIndex++)
		{
			var tagInfo = {};
			let Stride = FRAME_SCENE_POINTS_INFOR_SIZE * tagInfoIndex;
			//编号
			tagInfo.nIndex = dataView.getInt32(Stride + 0, true);
			//景物点x坐标
			tagInfo.xPoint = dataView.getUint16(Stride + 4, true);
			//景物点y坐标
			tagInfo.yPoint = dataView.getUint16(Stride + 6, true);
			
			//一级名称
			var jsNameBuf = new ArrayBuffer(64);
			jsNameBuf = ivsBuf.slice(Stride + 8);
			tagInfo.strName = ArrayBufferToStringAutoClip(jsNameBuf); 
			
			//使能标记
			tagInfo.enable = dataView.getInt8(Stride + 72, true);
			//标签类型
			tagInfo.titleType = dataView.getInt8(Stride + 73, true);
			//标签属性
			tagInfo.titleAttribute = dataView.getInt8(Stride + 74, true);
			tagInfo.sharpType = dataView.getInt8(Stride + 75, true);
			tagInfo.polygonNum = dataView.getInt8(Stride + 76, true);
			tagInfo.polygon = [];
			//与上一个中间空了三字节
			for(let i = 0;i< tagInfo.polygonNum*2;i+=2)
			{
				tagInfo.polygon[i] ={x: dataView.getInt8(Stride + 79 + 2*i, true),  
									 y: dataView.getInt8(Stride + 79 + 2*(i+1), true)};
			}

			tagInfos[tagInfoIndex] = tagInfo;
			jsNameBuf = null;
		}
		
		var msgType = 'ARTagInfoCallback';
		var msgData =  
		{	
			tagInfo: tagInfos,
		}
		
		sendMessage(nPort, msgType, msgData);
		
		pParseredBuf = ivsDataArray;
		dataView = null;
	}
	else
	{
		pParseredBuf = ivsDataArray;
	}
	
	var msgType = 'IVSDataCallBack';
	var msgData =  
	{	
		pBuf: pParseredBuf,
		nType: nType,
		nLen: nLen, 
		nReallen: nReallen,
	}
	
	sendMessage(nPort, msgType, msgData);
}

function sendMessage(nPort, msgType, msgData) 
{
	var event = 
	{
		nPort: nPort,
		msgType: msgType,
		msgData: msgData,
	};

    postMessage(event);
}

function ArrayBufferToString(buffer, encoding = 'utf-8') 
{
	const decoder = new TextDecoder(encoding);
	return decoder.decode(buffer);
}

function ArrayBufferToStringAutoClip(buffer, encoding = 'utf-8')
{
	const decoder = new TextDecoder(encoding);
	const uint8Array = new Uint8Array(buffer);
  
	let i = 0;
  
	while (i < uint8Array.length) {
	  if (uint8Array[i] === 0) {
		break;
	  }
	  i++;
	}
  
	return decoder.decode(buffer.slice(0,i));
}

function UTF16ToUTF8(UTF16Str) 
{
	var UTF8Arr = [];
	var byteSize = 0;
	for (var i = 0; i < UTF16Str.length; i++) 
	{
		//获取字符Unicode码值
		var code = UTF16Str.charCodeAt(i);

		//如果码值是1个字节的范围，则直接写入
		if (code >= 0x00 && code <= 0x7f) 
		{
			byteSize += 1;
			UTF8Arr.push(code);

			//如果码值是2个字节以上的范围，则按规则进行填充补码转换
		}
		else if (code >= 0x80 && code <= 0x7ff) 
		{
			byteSize += 2;
			UTF8Arr.push((192 | (31 & (code >> 6))));
			UTF8Arr.push((128 | (63 & code)))
		} 
		else if ((code >= 0x800 && code <= 0xd7ff) || (code >= 0xe000 && code <= 0xffff)) 
		{
			byteSize += 3;
			UTF8Arr.push((224 | (15 & (code >> 12))));
			UTF8Arr.push((128 | (63 & (code >> 6))));
			UTF8Arr.push((128 | (63 & code)))
		} 
		else if(code >= 0x10000 && code <= 0x10ffff)
		{
			byteSize += 4;
			UTF8Arr.push((240 | (7 & (code >> 18))));
			UTF8Arr.push((128 | (63 & (code >> 12))));
			UTF8Arr.push((128 | (63 & (code >> 6))));
			UTF8Arr.push((128 | (63 & code)))
		}
	}

	return UTF8Arr
}
