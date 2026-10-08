-- =====================================================================
-- Digital Museum Research Project
-- Phase 1 - Database Foundation
-- insert_metadata.sql
--
-- Populates theme, subtheme, and artefact with the curated 60-item
-- Smithsonian Open Access dataset (2 themes x 6 subthemes x 5 artefacts).
-- Source: /System Design/Artefact Metadata.xlsx, /System Design/Metadata Strategy.docx
-- =====================================================================

USE digitalmuseum;

SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM artefact;
ALTER TABLE artefact AUTO_INCREMENT = 1;
DELETE FROM subtheme;
ALTER TABLE subtheme AUTO_INCREMENT = 1;
DELETE FROM theme;
ALTER TABLE theme AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- THEME  (2 rows)
-- =====================================================================

INSERT INTO theme (theme_id, theme_name, theme_description) VALUES
    (1, 'Animals', 'Biological specimens including mammals, birds, reptiles, insects, marine life, and fossils.'),
    (2, 'Everyday Objects', 'Human-made objects including tools, writing implements, clothing, food-related items, household items, and toys.');

-- =====================================================================
-- SUBTHEME  (12 rows, 6 per theme)
-- =====================================================================

INSERT INTO subtheme (subtheme_id, theme_id, subtheme_name, subtheme_description) VALUES
    (1, 1, 'Mammals', 'Warm-blooded vertebrate specimens, including skulls, skeletons, and taxidermy mounts.'),
    (2, 1, 'Birds', 'Avian specimens, including taxidermy mounts, eggs, and feather samples.'),
    (3, 1, 'Fossils', 'Preserved remains or traces of prehistoric organisms.'),
    (4, 1, 'Reptiles', 'Cold-blooded scaled vertebrate specimens, including preserved and skeletal examples.'),
    (5, 1, 'Insects', 'Preserved arthropod specimens, including pinned and mounted examples.'),
    (6, 1, 'Marine Life', 'Preserved specimens and remains from ocean and aquatic environments.'),
    (7, 2, 'Tools', 'Hand tools and implements used for practical tasks.'),
    (8, 2, 'Writing', 'Objects related to writing, printing, and correspondence.'),
    (9, 2, 'Clothing', 'Garments and wearable items from various periods.'),
    (10, 2, 'Food-related', 'Objects associated with food preparation, storage, or consumption.'),
    (11, 2, 'Household Items', 'Everyday domestic objects used in the home.'),
    (12, 2, 'Toys', 'Objects designed for play or entertainment.');

-- =====================================================================
-- ARTEFACT  (60 rows, 5 per subtheme)
-- =====================================================================

INSERT INTO artefact
    (artefact_id, theme_id, subtheme_id, external_id, artefact_title, artefact_description, image_url, source_url, tags)
VALUES
    -- Mammals (Animals)
    (1, 1, 1, 'nmnheducation_10026876', 'African Lion Skull', 'Skull of Panthera leo, the African lion, whose wide jaw and long canines reflect its role as an apex predator.', 'https://ids.si.edu/ids/download?id=NMNH-EO_067340_SKULL_Lion_Panthera_leo_001.jpg', 'https://www.si.edu/object/lion:nmnheducation_10026876', 'skull,mammal,bone'),
    (2, 1, 1, 'nmnhvz_7592992', 'Red Fox Taxidermy Mount', 'Preserved specimen of Vulpes vulpes. Red foxes are adaptable omnivores found across forests, farmland, and cities throughout the northern hemisphere.', 'https://ids.si.edu/ids/download?id=NMNH-Vulpesvulpes1.jpg', 'https://www.si.edu/object/vulpes-vulpes:nmnhvz_7592992', 'mammal,fur,specimen'),
    (3, 1, 1, 'siris_arc_387812', 'Big Horn Sheep Skeleton', 'Full skeletal mount of Ovis canadensis. Bighorn sheep use their curved horns in dramatic head-butting contests to establish dominance.', 'https://ids.si.edu/ids/download?id=SIA-MNH-2320.jpg', 'https://www.si.edu/object/mounted-skeleton-bighorn-sheep:siris_arc_387812', 'skeleton,mammal,bone'),
    (4, 1, 1, 'nmnheducation_10841923', 'White-tailed Deer Antlers', 'Naturally shed antler pair from Odocoileus virginianus. Male deer regrow their antlers each year, shedding them after the breeding season.', 'https://ids.si.edu/ids/download?id=NMNH-EO_400085_White_Tailed_Deer_Odocoileus_virginianus_Mask.jpg', 'https://www.si.edu/object/white-tailed-deer:nmnheducation_10841923', 'antler,mammal,bone'),
    (5, 1, 1, 'nmnhvz_7227114', 'Pale Striped Ground Squirrel', 'Preserved specimen of Ictidomys tridecemlineatus pallidus, a burrowing ground squirrel known for the pale stripes running along its back.', 'https://ids.si.edu/ids/download?id=NMNH-SpermophilustridecemlineatuspallidusAllen1877_USNM016237_Skindorsal.jpg', 'https://www.si.edu/object/ictidomys-tridecemlineatus-pallidus:nmnhvz_7227114', 'mammal,fur,specimen'),
    -- Birds (Animals)
    (6, 1, 2, 'nmnhvz_4301642', 'Bullock''s Oriole', 'Preserved specimen of Icterus galbula bullockii. Males display bright orange and black plumage used to attract mates each breeding season.', 'https://ids.si.edu/ids/download?id=NMNH-USNM186125IcteridaeIcterusgalbulabullockii1.jpg', 'https://www.si.edu/object/icterus-galbula-bullockii:nmnhvz_4301642', 'bird,feather,specimen'),
    (7, 1, 2, 'nmnhvz_4439094', 'Red-tailed Hawk Wing', 'Spread wing of a Buteo jamaicensis. Red-tailed hawks are common North American raptors, often seen soaring on broad, rounded wings.', 'https://ids.si.edu/ids/download?id=NMNH-IMAG0135.jpg', 'https://www.si.edu/object/buteo-jamaicensis:nmnhvz_4439094', 'wing,bird,feather'),
    (8, 1, 2, 'nmnhpaleobiology_3354311', 'Deep-billed Crow Bones', 'Partial skeletal remains of Corvus impluviatus, an extinct crow species known only from fossils found in Hawaiian lava tube caves.', 'https://ids.si.edu/ids/download?id=NMNH-USNM_PAL_386427_Corvus_impluviatus.jpg', 'https://www.si.edu/object/corvus-impluviatus-james-olson-1991:nmnhpaleobiology_3354311', 'bird,bone,fossil'),
    (9, 1, 2, 'nmnhvz_15224411', 'Spectacled Flowerpecker', 'Preserved specimen of Dicaeum dayakorum, a small songbird first formally described by scientists as recently as 2019.', 'https://ids.si.edu/ids/download?id=NMNH-USNM_663246Dicaeum_dayakorum10.jpg', 'https://www.si.edu/object/dicaeum-dayakorum-saucier-et-al-2019:nmnhvz_15224411', 'bird,feather,specimen'),
    (10, 1, 2, 'nmnheducation_11174829', 'Wild Turkey Feather', 'Down feather from a Meleagris gallopavo, the wild turkey, native to North America and long domesticated by Indigenous peoples.', 'https://ids.si.edu/ids/download?id=NMNH-EO_401247_Wild_Turkey_Meleagris_gallopavo_001.jpg', 'https://www.si.edu/object/wild-turkey:nmnheducation_11174829', 'feather,bird,specimen'),
    -- Fossils (Animals)
    (11, 1, 3, 'nmnhpaleobiology_3368443', 'Ancient Ostrich Relative Fossil', 'Skull and axial remains of Pseudocrypturus cercanaxius, an early bird relative of modern ostriches that lived millions of years ago.', 'https://ids.si.edu/ids/download?id=NMNH-USNM336103.jpg', 'https://www.si.edu/object/pseudocrypturus-cercanaxius-houde-1988:nmnhpaleobiology_3368443', 'fossil,bone,bird'),
    (12, 1, 3, 'nmnheducation_100204', 'Fossil Rabbit', 'Fossilized mandible with teeth from Megalagus, an early rabbit relative that lived in North America roughly 30 million years ago.', 'https://ids.si.edu/ids/download?id=NMNH-EO_050878_Fossil_Rabbit_Megalagus_001.jpg', 'https://www.si.edu/object/fossil-rabbit:nmnheducation_10020422', 'fossil,bone,mammal'),
    (13, 1, 3, 'nmnheducation_10020826', 'Fossil Rhinoceros', 'Fossilized partial mandible with teeth from Subhyracodon, an early rhinoceros ancestor that roamed North America over 30 million years ago.', 'https://ids.si.edu/ids/download?id=NMNH-EO_051283_Running_Rhinoceros_Subhyracodon_Mask-000001.jpg', 'https://www.si.edu/object/fossil-rhinoceros:nmnheducation_10020826', 'fossil,bone,mammal'),
    (14, 1, 3, 'nmnheducation_10021002', 'Fossil Crocodile', 'Fossilized tooth from Thecachampsa, an extinct crocodilian that lived in what is now North America during the Miocene epoch.', 'https://ids.si.edu/ids/download?id=NMNH-EO_051460_Fossil_Crocodile_Thecachampsa_contusor_001.jpg', 'https://www.si.edu/object/fossil-crocodile-crocodile:nmnheducation_10021002', 'fossil,bone,reptile'),
    (15, 1, 3, 'nmnheducation_10019905', 'Fossil Sperm Whale', 'Fossilized molar tooth from Scaldicetus, an extinct sperm whale ancestor that hunted prey in ancient oceans millions of years ago.', 'https://ids.si.edu/ids/download?id=NMNH-EO_050360_Fossil_Sperm_Whale_Scaldicetus_001.jpg', 'https://www.si.edu/object/fossil-sperm-whale:nmnheducation_10019905', 'fossil,bone,marine'),
    -- Reptiles (Animals)
    (16, 1, 4, 'nmnhvz_6146546', 'Crocodile Skull', 'Skull specimen of Caiman yacare, a South American caiman species found across wetlands, rivers, and floodplains of the Pantanal region.', 'https://ids.si.edu/ids/download?id=NMNH-281839_skull_dorsal.jpg', 'https://www.si.edu/object/caiman-yacare:nmnhvz_6146546', 'skull,reptile,bone'),
    (17, 1, 4, 'nmnhvz_6042435', 'Agamidae Lizard', 'Specimen of Agama rueppelli occidentalis, an agamid lizard native to arid regions of northeastern Africa, known for its rapid movement.', 'https://ids.si.edu/ids/download?id=NMNH-USNM_66927_dorsal.jpg', 'https://www.si.edu/object/agama-rueppelli-occidentalis:nmnhvz_6042435', 'reptile,scale,specimen'),
    (18, 1, 4, 'nmnhvz_6283987', 'Egyptian Tortoise', 'Specimen of Testudo kleinmanni, one of the smallest tortoise species in the world and now critically endangered in the wild.', 'https://ids.si.edu/ids/download?id=NMNH-USNM_139092_dorsal.jpg', 'https://www.si.edu/object/testudo-kleinmanni:nmnhvz_6283987', 'reptile,shell,specimen'),
    (19, 1, 4, 'nmnhvz_6277586', 'African Chameleon', 'Specimen of Chamaeleo africanus, a chameleon species known for its colour-changing skin, used for camouflage and communicating mood.', 'https://ids.si.edu/ids/download?id=NMNH-USNM_132157_lateral.jpg', 'https://www.si.edu/object/chamaeleo-africanus:nmnhvz_6277586', 'reptile,scale,specimen'),
    (20, 1, 4, 'siris_arc_402282', 'Stegosaurus Dinosaur Skeleton', 'Full skeletal mount of Stegosaurus, a plant-eating dinosaur recognised by the row of bony plates running along its spine.', 'https://ids.si.edu/ids/download?id=SIA-SIA_000095_B44A_F14_018.jpg', 'https://www.si.edu/object/stegosaurus-skeleton:siris_arc_402282', 'fossil,bone,reptile'),
    -- Insects (Animals)
    (21, 1, 5, 'nmnhentomology_16355587', 'Malaria Mosquito Microscope Slide', 'Prepared slide of Anopheles quadrimaculatus, a mosquito species historically responsible for spreading malaria across parts of North America.', 'https://ids.si.edu/ids/download?id=NMNH-USNMENT00863207_Anopheles_quadrimaculatus_adult_hab.jpg', 'https://www.si.edu/object/anopheles-anopheles-quadrimaculatus:nmnhentomology_16355587', 'insect,wing,slide'),
    (22, 1, 5, 'nmnheducation_10004232', 'Butterfly Specimen', 'Mounted specimen of Panacea divalis, a brightly coloured butterfly native to the tropical forests of Central and South America.', 'https://ids.si.edu/ids/download?id=NMNH-EO_023404_Butterfly_Panacea_divalis_001.jpg', 'https://www.si.edu/object/butterfly:nmnheducation_10004232', 'insect,wing,specimen'),
    (23, 1, 5, 'nmah_1815861', 'Insect Wing', 'Preserved section of an insect wing, showing the delicate vein structure that provides both strength and flexibility for flight.', 'https://ids.si.edu/ids/download?id=NMAH-ET2017-12948-000001.jpg', 'https://www.si.edu/object/insect-wing:nmah_1815861', 'wing,insect,feather'),
    (24, 1, 5, 'npm_1991.0414.1', 'Queen Honeybee Cage', 'Wooden box used for shipping a queen honeybee, allowing beekeepers to safely transport and introduce new queens to a hive.', 'https://ids.si.edu/ids/download?id=NPM-1991_0414_1az.jpg', 'https://www.si.edu/object/queen-honeybee-cage:npm_1991.0414.1', 'insect,wood,tool'),
    (25, 1, 5, 'nmnheducation_10866557', 'Giant Stag Beetle', 'Specimen of Dorcus titanus, a giant stag beetle whose males use oversized jaw-like mandibles to battle rivals for mates.', 'https://ids.si.edu/ids/download?id=NMNH-EO_400264_Giant_Stag_Beetle_Dorcus_titanus_001-000001.jpg', 'https://www.si.edu/object/giant-stag-beetle:nmnheducation_10866557', 'insect,specimen,mandible'),
    -- Marine Life (Animals)
    (26, 1, 6, 'nmnheducation_10008363', 'Pelagic Octopus Shell', 'Shell specimen of the winged argonaut, Argonauta hians, a pelagic octopus that secretes a thin shell to protect its eggs.', 'https://ids.si.edu/ids/download?id=NMNH-EO_031602_Brown_Paper_Nautilus_Argonauta_hians_001.jpg', 'https://www.si.edu/object/winged-argonaut:nmnheducation_10008363', 'shell,marine,specimen'),
    (27, 1, 6, 'siris_arc_402549', 'Blue Whale', 'Life-sized model of Balaenoptera musculus, the blue whale, the largest animal known to have ever lived on Earth.', 'https://ids.si.edu/ids/download?id=SIA-SIA_000095_B44_F21_007.jpg', 'https://www.si.edu/object/hall-life-sea-museum-natural-history-blue-whale:siris_arc_402549', 'marine,model,whale'),
    (28, 1, 6, 'nmnhinvertebratezoology_164793', 'California Spiny Lobster', 'Specimen of Panulirus interruptus, a spiny lobster found along the Pacific coast, identified by its long antennae and spiny shell.', 'https://ids.si.edu/ids/download?id=NMNH-USNM_210038_Panulirus_interruptus_stgIII.jpg', 'https://www.si.edu/object/panulirus-interruptus:nmnhinvertebratezoology_164793', 'marine,shell,invertebrate'),
    (29, 1, 6, 'nmnheducation_10008991', 'Common Starfish', 'Specimen of Asterias rubens, the common starfish, which can regenerate lost arms and moves using tiny tube feet.', 'https://ids.si.edu/ids/download?id=NMNH-EO_032529_Northern_Sea_Star_Asterias_rubens_001.jpg', 'https://www.si.edu/object/common-sea-star-starfish-starfish:nmnheducation_10008991', 'marine,starfish,specimen'),
    (30, 1, 6, 'nmnheducation_10009334', 'Staghorn Coral Fragment', 'Branching coral sample from the staghorn coral family, reef-building corals that grow in fast branching shapes resembling deer antlers.', 'https://ids.si.edu/ids/download?id=NMNH-EO_033255_Stony_Coral_Acropora_001.jpg', 'https://www.si.edu/object/hard-coral-staghorn-coral-stony-coral-staghorn-coral-stony-coral-staghorn-coral:nmnheducation_10009334', 'coral,marine,specimen'),
    -- Tools (Everyday Objects)
    (31, 2, 7, 'nmah_659686', 'Iron Hammer', 'Cobbler''s iron hammer used by an African American shoemaker, reflecting the everyday tools of skilled tradespeople in earlier centuries.', 'https://ids.si.edu/ids/download?id=NMAH-AHB2011q39757.jpg', 'https://www.si.edu/object/hammer-cobblers:nmah_659686', 'tool,metal,wood'),
    (32, 2, 7, 'nmah_315482', 'Scroll-Saw Blade', 'Metal scroll-saw blade that belonged to woodworker Peter Glass, used to cut intricate curved patterns into wood by hand.', 'https://ids.si.edu/ids/download?id=NMAH-JN2016-03374-000001.jpg', 'https://www.si.edu/object/metal-scroll-saw-blade-used-peter-glass:nmah_315482', 'tool,metal,blade'),
    (33, 2, 7, 'acm_2001.5001.0001', 'Tongue Plane', 'Tongue plane made by Cesar Chelor, one of the earliest known Black American toolmakers, used to shape grooves in wood.', 'https://ids.si.edu/ids/download?id=ACM-acmobj-200150010001-r2.jpg', 'https://www.si.edu/object/tongue-plane-made-cesar-chelor:acm_2001.5001.0001', 'tool,wood,metal'),
    (34, 2, 7, 'nmah_998854', 'Hand Press', 'Patent model for a hand press, submitted to demonstrate a new mechanical design before the invention could be officially patented.', 'https://ids.si.edu/ids/download?id=NMAH-MAH-69659.jpg', 'https://www.si.edu/object/patent-model-hand-press:nmah_998854', 'tool,metal,mechanical'),
    (35, 2, 7, 'chndm_1937-93-2-a_b', 'Scissor-Like Instrument', 'Tool with two cylindrical rods and handles, resembling scissors, likely used for gripping or shaping materials in a workshop setting.', 'https://ids.si.edu/ids/download?id=CHSDM-6906EE25388D2_01-000001.jpg', 'https://www.si.edu/object/tool:chndm_1937-93-2-a_b', 'tool,metal,handle'),
    -- Writing (Everyday Objects)
    (36, 2, 8, 'nmah_316228', 'Quill Pen', 'Pen made from swan feathers. Quills were the primary Western writing tool for over a thousand years, before steel nibs.', 'https://ids.si.edu/ids/download?id=NMAH-JN2014-3979.jpg', 'https://www.si.edu/object/quill-pen:nmah_316228', 'writing,feather,tool'),
    (37, 2, 8, 'nmah_589622', 'Ink Bottle', 'Green, transparent glass bottle used to hold ink, a common household item before fountain pens and ink cartridges became widespread.', 'https://ids.si.edu/ids/download?id=NMAH-AHB2016q024630.jpg', 'https://www.si.edu/object/bottle-ink:nmah_589622', 'writing,glass,bottle'),
    (38, 2, 8, 'nmah_849976', 'Typewriter', 'Patent model for a typewriter developed by George W. N. Yost, part of an invention that transformed 19th-century office work.', 'https://ids.si.edu/ids/download?id=NMAH-AHB2012q25939.jpg', 'https://www.si.edu/object/george-w-n-yost-typewriter-patent-model:nmah_849976', 'writing,metal,mechanical'),
    (39, 2, 8, 'npm_1990.0564.1', 'Mail Employee''s Notebook', 'Notebook used by conductors, agents, drivers, and station managers to record details of overland mail routes and deliveries.', 'https://ids.si.edu/ids/download?id=NPM-1990_0564_1_closed.jpg', 'https://www.si.edu/object/overland-mail-employees-notebook:npm_1990.0564.1', 'writing,paper,book'),
    (40, 2, 8, 'nmah_706652', 'Telegraph Register', 'Model used to record Morse code signals, part of the telegraph technology that revolutionised long-distance communication in the 19th century.', 'https://ids.si.edu/ids/download?id=NMAH-NMAH2001-09492.jpg', 'https://www.si.edu/object/telegraph-register:nmah_706652', 'writing,metal,mechanical'),
    -- Clothing (Everyday Objects)
    (41, 2, 9, 'chndm_1947-47-13', 'Wool Embroidered Cap', 'Women''s wool cap embroidered with flowers and leaves, showing the decorative needlework traditionally used to personalise everyday garments.', 'https://ids.si.edu/ids/download?id=CHSDM-96797_01-000004.jpg', 'https://www.si.edu/object/cap:chndm_1947-47-13', 'clothing,fabric,wool'),
    (42, 2, 9, 'chndm_1985-119-2-a_b', 'Shoe Buckles', 'Silver decorative shoe buckles, once a fashionable and practical way to fasten footwear before laces and modern shoe fastenings.', 'https://ids.si.edu/ids/download?id=CHSDM-DEE4FEB60ADD2-000001.jpg', 'https://www.si.edu/object/shoe-buckles:chndm_1985-119-2-a_b', 'clothing,metal,decorative'),
    (43, 2, 9, 'chndm_1936-45-1', 'Dressing Gown', 'Man''s dressing gown with contrasting front edgings, lapels, and cuffs, worn as informal indoor clothing in earlier centuries.', 'https://ids.si.edu/ids/download?id=CHSDM-55623_01-000001.jpg', 'https://www.si.edu/object/dressing-gown:chndm_1936-45-1', 'clothing,fabric,textile'),
    (44, 2, 9, 'chndm_1981-1-1', 'Scarf Sample', 'Quarter section of a printed scarf featuring a wavy floral border, used to test textile patterns before full production.', 'https://ids.si.edu/ids/download?id=CHSDM-1981-1-1MattFlynn.jpg', 'https://www.si.edu/object/scarf-sample:chndm_1981-1-1', 'clothing,fabric,textile'),
    (45, 2, 9, 'chndm_1941-86-2-a_b', 'Leather Gloves', 'Leather gloves with gauntlet cuffs, cut in a delicate lace-like pattern to combine protection with decorative craftsmanship.', 'https://ids.si.edu/ids/download?id=CHSDM-75525_01-000001.jpg', 'https://www.si.edu/object/gloves:chndm_1941-86-2-a_b', 'clothing,leather,fabric'),
    -- Food-related (Everyday Objects)
    (46, 2, 10, 'nmah_316806', 'Wooden Spoon', 'A spoon carved from wood, one of the oldest and most common food preparation tools used across many cultures.', 'https://ids.si.edu/ids/download?id=NMAH-AHB2013q097685.jpg', 'https://www.si.edu/object/wooden-spoon:nmah_316806', 'food,wood,tool'),
    (47, 2, 10, 'nmaahc_2020.32.5.1', 'Silver Tea Pot', 'Sterling silver tea pot belonging to George III, reflecting the elaborate tableware associated with formal tea traditions of the era.', 'https://ids.si.edu/ids/download?id=NMAAHC-2020_32_5_1_003.jpg', 'https://www.si.edu/object/george-iii-sterling-silver-tea-pot:nmaahc_2020.32.5.1', 'food,silver,metal'),
    (48, 2, 10, 'nmah_301164', 'Soup Ladle', 'Silver-plated ladle used for serving soup, a practical piece of formal dinnerware common in wealthier households of the period.', 'https://ids.si.edu/ids/download?id=NMAH-AHB2014q061072.jpg', 'https://www.si.edu/object/soup-ladle:nmah_301164', 'food,metal,ladle'),
    (49, 2, 10, 'nmah_300557', 'Bowl', 'Footed bowl engraved with a ship sailing calm waters, likely used as a decorative or ceremonial serving piece.', 'https://ids.si.edu/ids/download?id=NMAH-AHB2014q056791-000003.jpg', 'https://www.si.edu/object/bowl:nmah_300557', 'food,metal,bowl'),
    (50, 2, 10, 'chndm_1985-103-23', 'Knife', 'Tapered, curved knife with a pointed blade, likely used for food preparation or serving at the dining table.', 'https://ids.si.edu/ids/download?id=CHSDM-573378EC25D02-000001.jpg', 'https://www.si.edu/object/knife:chndm_1985-103-23', 'food,metal,blade'),
    -- Household Items (Everyday Objects)
    (51, 2, 11, 'nmah_316816', 'Table', 'Oak table finished in red, an example of the sturdy, functional furniture found in many 19th-century homes.', 'https://ids.si.edu/ids/download?id=NMAH-AHB2014q062827.jpg', 'https://www.si.edu/object/table:nmah_316816', 'household,wood,furniture'),
    (52, 2, 11, 'nmah_306576', 'Chair', 'Walnut chair with a fabric seat, combining durable hardwood construction with upholstered comfort common in period furniture.', 'https://ids.si.edu/ids/download?id=NMAH-AHB2014q061920.jpg', 'https://www.si.edu/object/chair:nmah_306576', 'household,wood,furniture'),
    (53, 2, 11, 'chndm_1967-66-27', 'Desk Clock', 'Round clock made for a desk, designed to keep time in a compact form suitable for a study or office.', 'https://ids.si.edu/ids/download?id=CHSDM-139913_01-000001.jpg', 'https://www.si.edu/object/desk-clock:chndm_1967-66-27', 'household,clock,metal'),
    (54, 2, 11, 'nmaahc_2012.113.1a-m', 'Bed Frame', 'Wooden bed frame designed by Henry Boyd, a formerly enslaved inventor known for his improved bedstead joint design.', 'https://ids.si.edu/ids/download?id=NMAAHC-2012_113_1_001.jpg', 'https://www.si.edu/object/bed-frame-designed-henry-boyd:nmaahc_2012.113.1a-m', 'household,wood,furniture'),
    (55, 2, 11, 'chndm_2019-28-16', 'Curtain', 'A fabric curtain used to block out sunlight, a simple household textile that also added privacy and decoration indoors.', 'https://ids.si.edu/ids/download?id=CHSDM-73939CB2EE3B2_01-000001.jpg', 'https://www.si.edu/object/curtain:chndm_2019-28-16', 'household,fabric,textile'),
    -- Toys (Everyday Objects)
    (56, 2, 12, 'saam_2011.37.11', 'Toy Bicycle Rider', 'Figurine of a man riding a bicycle, a popular toy that reflected the growing craze for cycling in this era.', 'https://ids.si.edu/ids/download?id=SAAM-2011.37.11_2.jpg', 'https://www.si.edu/object/toy-bicycle-rider:saam_2011.37.11', 'toy,metal,figure'),
    (57, 2, 12, 'chndm_1968-101-30-20', 'Figure Toy', 'Paper doll of a female figure in a yellow dress, a popular and inexpensive toy for children in earlier decades.', 'https://ids.si.edu/ids/download?id=CHSDM-B4B5C121C6822-000001.jpg', 'https://www.si.edu/object/figure-toy:chndm_1968-101-30-20', 'toy,paper,figure'),
    (58, 2, 12, 'nmah_1343298', 'Hand Pumper Toy', 'Toy fire pumper made from tin, iron, and wood, modelled after real horse-drawn pumper engines used by firefighters.', 'https://ids.si.edu/ids/download?id=NMAH-AHB2011q00152.jpg', 'https://www.si.edu/object/hand-pumper-toy:nmah_1343298', 'toy,metal,wood'),
    (59, 2, 12, 'nmah_1346157', 'Toy Trumpet', 'Silver-plated toy speaking trumpet, a novelty instrument designed to amplify a child''s voice rather than play music.', 'https://ids.si.edu/ids/download?id=NMAH-AHB2011q00578.jpg', 'https://www.si.edu/object/toy-trumpet:nmah_1346157', 'toy,metal,instrument'),
    (60, 2, 12, 'nmah_491375', 'Teddy Bear', 'A stuffed teddy bear, a toy style that emerged in the early 1900s, named after President Theodore ''Teddy'' Roosevelt.', 'https://ids.si.edu/ids/download?id=NMAH-93-7206.jpg', 'https://www.si.edu/object/teddy-bear:nmah_491375', 'toy,fabric,stuffed');
