# blogger-importer-opensource


## ⚠️AI-Generated Code Notice

This repository contains code and documentation generated primarily using AI tools (e.g., Claude by Anthropic). While the overall direction and purpose were set by a human, most of the code was produced automatically, with minimal manual editing.

Users should treat this code as experimental and review it carefully before using it in production or security-sensitive contexts.

I will fully review the code someday in the future, but I have to honestly say that most of the current code was done by LLMs. Make sure you backup your sites first or create a test site to test this plugin first. There is no garenteen that this plugin won't damage other existing contents.



## Features:

An extended version of the default blogger to wordpress importer.

This importer plugin can:

- Import all posts, pages, and commands into wordpress from a blogger-exported .xml file.
- Import all blogger post tags into wordpress tag.
- Import and save all images in the media library. (not tested for videos)
- Turn the contents in the posts into blocks. (tested: paragraph, headings, image blocks)
- Keep links, alignment settings, and image caption remains the same.
- Support uft-8 characters. (tested: English and Chinese contents)

## Developing features (create an issue to list more ideas here) :

- Create new authors corresponding to the author name in blogger.


## Usage

**==The plugin's tools page (graphic user interface) is currently broken, please use command line to run this plugin.==**

Upload the whole plugin file to a folder under wp-content/plugin, and then activate the plugin in the wordpress dashboard.

After activating the plugin, run

```bash
wp blogger-import import <xml path> <options>
```

Options:

1. [--skip-media]: Skip importing media files.
2. [--map-file=<file>]: Path to output mapping file (CSV format).
3. [--author=<id>]: User ID to use as the author of imported content.
4. [--use-current-user]: Use current WordPress user as the author for all imported content.



## Initial reason to develop this plugin:

I had a blogger site which contains around 1500 posts in Mandarin and English, and its exported xml is over 20mb, the default blogger importer file size limit.

In addition to the file size limit, the default importer cannot treat Chinese characters properly. Also, it can't convert contents into wordpress Gutenberg Block Editor.

My another important goal was to save the images into the wordpress media library, rather than linking the image to the original blogger site. However, this feature is set to be a premium features in all other blogger importer plugin that I have tried.

None of the current plugins can fulfill my need, or there's a paywall to some specific features that I need, so I decided to make my own one. 


## License

This project is licensed under the GPLv3 License. Feel free for making any changes to make this wordpress plugin better. Contribution is highly welcomed.
