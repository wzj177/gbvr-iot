在优化Http文件服务器时，遇到了一些疑惑，就是怎么获取一个文件的大小？
目前我知道有两种方式，分别是：
- 通过stat函数：
```c++
uint64_t getFileLength(const char *file){
    struct stat tFileStat;
    if (0 != stat(file, &tFileStat)) {
        //文件不存在
        return 0;
    }
    return tFileStat.st_size;
}
```

- 通过ftell方式：
```c++
uint64_t getFileLength(FILE *fp){
    auto current = ftell(fp);
    fseek(fp,0L,SEEK_END); /* 定位到文件末尾 */
    auto end  = ftell(fp); /* 得到文件大小 */
    fseek(fp,current,SEEK_SET);
    return end - current;
}
```

前不久，我在修改ZLMediaKit的http文件服务器时，在实现mmap映射文件的同时，为了精简代码，把代码由stat方式改成了ftell方式，
但是后面不仅就收到了hls直播延时增加的反馈，我怀疑可能是这个改动导致的，所以今天特意测试了两者的性能对比，测试代码如下：
```c++
#include <sys/stat.h>
#include <cstdint>
#include <cstdio>
#include <sys/time.h>
#include <string>
using namespace std;
#define MAX_COUNT 1 * 1000000

uint64_t getFileLength(FILE *fp){
    auto current = ftell(fp);
    fseek(fp,0L,SEEK_END); /* 定位到文件末尾 */
    auto end  = ftell(fp); /* 得到文件大小 */
    fseek(fp,current,SEEK_SET);
    return end - current;
}

uint64_t getFileLength(const char *file){
    struct stat tFileStat;
    if (0 != stat(file, &tFileStat)) {
        //文件不存在
        return 0;
    }
    return tFileStat.st_size;
}


static inline uint64_t getCurrentMicrosecondOrigin() {
#if !defined(_WIN32)
    struct timeval tv;
    gettimeofday(&tv, NULL);
    return tv.tv_sec * 1000000LL + tv.tv_usec;
#else
    return  std::chrono::duration_cast<std::chrono::microseconds>(std::chrono::system_clock::now().time_since_epoch()).count();
#endif
}

class TimePrinter {
public:
    TimePrinter(const string &str){
        _str = str;
        _start_time = getCurrentMicrosecondOrigin();
    }
    ~TimePrinter(){
        printf("%s占用时间:%d ms\n",_str.data(),(int)((getCurrentMicrosecondOrigin() - _start_time) / 1000));
    }

private:
    string _str;
    uint64_t _start_time;
};

int main(int argc, char *argv[]) {
    const char *file = "/Users/xzl/git/ZLMediaKit/release/mac/Debug/0ca86b00ccd5783194b13be6b0940c43.jpg";
    FILE *fp = fopen(file,"rb");
    {
        TimePrinter printer("stat方式获取文件大小:");
        for(int i = 0; i < MAX_COUNT ; ++i){
            getFileLength(file);
        }
    }

    {
        TimePrinter printer("ftell方式获取文件大小:");
        for(int i = 0; i < MAX_COUNT ; ++i){
            getFileLength(fp);
        }
    }

    return 0;
}


```

在我的电脑上，测试获取一个7KB的小文件大小，调用100万次，对比耗时如下：

```
stat方式获取文件大小:占用时间:1691 ms
ftell方式获取文件大小:占用时间:2590 ms
```

然后我有测试了一个600MB的大文件，调用100万次，对比耗时如下：
```
stat方式获取文件大小:占用时间:1682 ms
ftell方式获取文件大小:占用时间:2551 ms
```

我的电脑是2018款的256GB版本的macbook pro,我这里特意指出容量大小，是因为不同容量大小的ssd读写速度差别很大。
之后我又在一台linux虚拟机(宿主机是机械硬盘)上运行了同样的测试，结果也几乎一致。

从上述测试结果看，得出的结论是：

- 文件的大小并不会影响速度，所以可以肯定的是，这两种方式都没有文件io的操作。
- ftell和stat方式每次执行时间都很短，也没有拉开差距，fseek耗时多点应该是系统调用次数更多的原因。

