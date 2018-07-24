NLU Transcoder
---

For **PHP7.1**.

This little PHP CLI tool transcodes Alexa Skill / Wit.ai / DialogFlow backup files in another format for ease of switching to another platform if needed.

It does its best to recover the model data and to transcode it as accurately as possible, even though technically this is not possible since 1. each platform uses custom entities for common types (dates, numbers, ...) that are generally not exported in the archive files and 2. the inner workings of each platform makes it hard to match all expressions in the training model.

Use at your own risk. PRs welcome ! :)

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

_As of 23/07/2018_ :

|                        | _to_ Wit   | _to_ DialogFlow  | _to_ Alexa |
| ---------------------- | ---------- | ---------------- | ---------- |
| _from_ **Wit**         |   ✅       |   ✅             |   ✅       |
| _from_ **DialogFlow**  |   ✅       |   ✅             |   ✅       |
| _from_ **Alexa**       |   ✅       |   -              |   ✅       |

### Known limitations / bugs

  - _Platform_-specific entities (such as `wit/datetime` or DialogFlow's `@sys.duration`) are treated as user-defined entities (`free-text` for Wit, for instance). In the case of Alexa specific entities, such as `AMAZON.DATE`, placeholders are put in the expressions because the exported model does not contain utterances for these entities
  - Alexa's  `AMAZON.FallbackIntent`, `AMAZON.CancelIntent`, `AMAZON.HelpIntent` and `AMAZON.StopIntent` are ignored when exporting
  - When exporting from an Alexa skill, expressions (_or samples_) are reconstructed from the first synonym of each slot, thus leading to sentences that can be meaningless in natural language. They are accurate _training-wise_, though
  - Not tested on high-complexity models

### License

MIT