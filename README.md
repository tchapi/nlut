NLU Transcoder
---

For **PHP7.1**.

This little PHP CLI tool transcodes Alexa Skill / Wit.ai / DialogFlow backup files in another format for ease of switching to another platform if needed.

### Installation

_No steps are necessary prior to using the tool._

### Usage

    ./nlut.php --help

Get info on an archive file

    ./nlut.php --source MyWitProject.zip
    ----------------------------------------
    This is a Wit.ai archive
    App name     : MyWitProject
    Entities     : 17
    Intents      : 5
    Expressions  : 3624
    ----------------------------------------

Transcode a DialogFlow archive into the Wit format

    ./nlut.php --source MyDFArchive.zip --export MyProject.zip --format WIT
    ----------------------------------------
    This is a DialogFlow archive
    App name     : project-13d59
    Entities     : 17
    Intents      : 5
    Expressions  : 3624
    ----------------------------------------
    Exporting to the Wit format
    File MyProject.zip written.

### Tests

|                        | _to_ Wit   | _to_ DialogFlow  | _to_ Alexa |
| ---------------------- | ---------- | ---------------- | ---------- |
| _from_ **Wit**         |   ✅       |   -              |   -        |
| _from_ **DialogFlow**  |   ✅       |   ✅             |   -        |
| _from_ **Alexa**       |   -        |   -              |   ✅       |

### Known limitations / bugs

  - _Platform_-specific entities (such as `wit/datetime` or DialogFlow's `@sys.duration`) are treated as user-defined entities (`free-text` for Wit, for instance)
  - Not tested on high-complexity models

### License

MIT