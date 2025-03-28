# Chinese-genealogy-webtrees-plugin

## Note: this plugin can only process and generate Chinese genealogy graph. 

webtrees 中国家谱图打印插件, 可导出pdf文件

This plugin is for webtrees and is to print Chinese genealogy graph pdf as per the hanging line style(see the pic 1)

To install the plugin, copy the entire plugin folder to webtrees modules_v4 folder. Then you should be able to find it under the Reports tab.

![webtrees3](https://user-images.githubusercontent.com/32056680/150491694-6a12f750-4908-4dba-9ba6-46b79c1317b9.png)

![webtrees1](https://user-images.githubusercontent.com/32056680/150491664-e94322b2-d597-47af-ac21-82efc979a55f.png)

![webtrees4](https://user-images.githubusercontent.com/32056680/151151829-35f91994-7e4e-4132-a1da-3a9b57e77c72.png)


## Frontend switch options

printing mode is configurable with options defined as follows:

- Show genealogy all - The switch to print a single person's derived genealogy tree, or print all people's genealogy tree. 
  In the latter situation, all the top ancestors are automatically resolved and shown in the "fish" area. Users should input each top ancestor's generation order so that all their decedents' ones are determined. Each top ancestor holds a separate branch.
- Show resume - Print personal details table
- Show generation levels - (display when uncheck "Show genealogy all") Print the individual's relatives whose generation place, counting upwards or downwards, are up to the number specified. Same idea as "Generations" entry in the official report "related families"
- Choose relatives - (only display when uncheck "Show genealogy all"). Same as "Choose relatives" in the official report "related families"
- Show photos - Print persons' photos in personal details table.
- Show birth and death date - Similar to "Show photos" concept
- Show caste - Similar to "Show photos" concept
- Show occupations - Similar to "Show photos" concept
- Show residence - Similar to "Show photos" concept
- Custom header - Print these words vertically on the upper part of the left margin
- Custom margin - Print these words vertically on the lower part of the left margin
- Page size - Print A3(landscape) or A4(portrait)
- Use colors - Print all the females in the hanging line part with red color for distinguishing purpose





