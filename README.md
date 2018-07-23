NLU Transcoder
---

For **PHP7.1**.

This little PHP CLI tool transcodes Alexa Skill / Wit.ai / DialogFlow backup files in another format for ease of switching to another platform if needed.

### Installation

_No steps are necessary prior to using the tool._

### Usage

    ./nlut.php --help

### Tests

|                        | _to_ Wit   | _to_ DialogFlow  | _to_ Alexa |
| ---------------------- | ---------- | ---------------- | ---------- |
| _from_ **Wit**         |   ✅       |   -              |   -        |
| _from_ **DialogFlow**  |   ✅       |   ✅             |   -        |
| _from_ **Alexa**       |   -        |   -              |   ✅       |

### License

MIT