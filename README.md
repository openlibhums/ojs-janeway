# OJS-Janeway
An OJS plugin for exporting data for ingest into Janeway, in a custom JSON format


## Installation instructions

Download the release compatible with your version of OJS and copy it under the `plugins/generic` directory of your OJS install. The directory must be named `janeway` and not `ojs-janeway` (e.g.: `git clone https://github.com/BirkbeckCTP/ojs-janeway.git plugins/generic/janeway`. 
It can also be installed through OJS web interface if that feature is enabled in your installation (This requires configuring your web server with write permissions over the plugins directory)

## Usage
 - As a Journal Manager or administrator, enable the "Janeway export" plugin for the journal to be exported. The plugin is listed under "Generic Plugins"
 - As a Journal manager visit the path `/janeway` under your journal url (e.g.: https://example.org/index.php/journal/janeway)
 - The results can be filtered by article stage, controlled by the `request-type` parameter that can be set via the query string (e.g: https://example.org/index.php/journal/janeway?request_type=published)
    - Currently supported stages are: `"published"`, `"in_editing"` and `"in_review"`.
