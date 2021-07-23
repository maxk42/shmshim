#SHMSHIM

## A shared-memory data loading shim for speeding up your web applications.

**shmshim** is a utility for loading data into shared memory so it can be quickly read at run-time by short-lived applications such as PHP or perl scripts.  It supports multiple serialization formats including JSON and hot-reloading of static assets.  It can also generate data at run-time.

Its purpose it to load static or infrequently-changing assets such as HTML files, configuration data, or logs without pausing to make round-trips to the disk.

Its advantages include no connection / start-up time, the ability to hot-reload files, and an easily programmable unix interface that allows it to process or manipulate data before loading it.


### SERIALIZATION STRATEGIES

shmshim supports multiple serialization strategies when storing data in shared memory.  Serialization strategies are used for data portability and addressability.  The following strategies are supported by default:

#### json
Encode data as a json object.

#### raw
Concatenate all data and return it without any further encoding.  This is a good solution for loading a single file such as a configuration file or other frequently-needed asset.

#### simple
The simple format is defined as follows:

```text

Type    Name        Description
uint64  Length      The file begins with a header consisting of a single little-endian unsigned 64-bit integer which specifies how many records follow.
uint8   NameLen     Each record begins with a one-byte unsigned integer specifying how long the record's Name is.  The Name is a unique key (up to 255 characters in length) which is used to identify the data record.
char *  Name        The name is a key uniquely identifying this record, which is exactly NameLen bytes long.
uint64  DataLen     A little-endian 64-bit unsigned integer specifies the length of the data to follow.
char *  Data        Finally, the data of a single record is stored in a series of raw bytes.


Data to be serialized:

  One:    ABC
  Two:    DEF
  Three:  GHI

Hex dump of result, with letters below correlating to each part:

03  00  00  00  00  00  00  00  03  4F  6E  65  03  00  00  00  00  00  00  00  41  42  43  03  54  77  6F  03  00  00  00  00  00  00  00  44  45  46  05  54  68  72  65  65  03  00  00  00  00  00  00  00  47  48  49
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA  BB  CCCCCCCCCC  DDDDDDDDDDDDDDDDDDDDDDDDDDDDDD  EEEEEEEEEE  BB  CCCCCCCCCC  DDDDDDDDDDDDDDDDDDDDDDDDDDDDDD  EEEEEEEEEE  BB  CCCCCCCCCCCCCCCCCC  DDDDDDDDDDDDDDDDDDDDDDDDDDDDDD  EEEEEEEEEE

Key:
A - Length
B - NameLen
C - Name
D - DataLen
E - Data

Total: 52 bytes

```
