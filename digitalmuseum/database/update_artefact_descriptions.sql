-- =====================================================================
-- Digital Museum Research Project
-- Phase 10 - Pilot Study
-- update_artefact_descriptions.sql
--
-- Updates artefact_description for all 60 artefacts to 15-20 word
-- versions (from ~5-10 words), following pilot feedback that the
-- original descriptions did not add enough learning value. Length was
-- kept within the range estimated to preserve the existing dwell
-- threshold bands in adaptive_rules.php / insert_rules.sql (light
-- 3-8s, engaged 8-15s, deep 15-30s, immersive >30s) without requiring
-- a threshold change. Source: Artefact Metadata.xlsx (System Design).
-- =====================================================================

USE digitalmuseum;

UPDATE artefact SET artefact_description = 'Skull of Panthera leo, the African lion, whose wide jaw and long canines reflect its role as an apex predator.' WHERE artefact_id = 1;
UPDATE artefact SET artefact_description = 'Preserved specimen of Vulpes vulpes. Red foxes are adaptable omnivores found across forests, farmland, and cities throughout the northern hemisphere.' WHERE artefact_id = 2;
UPDATE artefact SET artefact_description = 'Full skeletal mount of Ovis canadensis. Bighorn sheep use their curved horns in dramatic head-butting contests to establish dominance.' WHERE artefact_id = 3;
UPDATE artefact SET artefact_description = 'Naturally shed antler pair from Odocoileus virginianus. Male deer regrow their antlers each year, shedding them after the breeding season.' WHERE artefact_id = 4;
UPDATE artefact SET artefact_description = 'Preserved specimen of Ictidomys tridecemlineatus pallidus, a burrowing ground squirrel known for the pale stripes running along its back.' WHERE artefact_id = 5;
UPDATE artefact SET artefact_description = 'Preserved specimen of Icterus galbula bullockii. Males display bright orange and black plumage used to attract mates each breeding season.' WHERE artefact_id = 6;
UPDATE artefact SET artefact_description = 'Spread wing of a Buteo jamaicensis. Red-tailed hawks are common North American raptors, often seen soaring on broad, rounded wings.' WHERE artefact_id = 7;
UPDATE artefact SET artefact_description = 'Partial skeletal remains of Corvus impluviatus, an extinct crow species known only from fossils found in Hawaiian lava tube caves.' WHERE artefact_id = 8;
UPDATE artefact SET artefact_description = 'Preserved specimen of Dicaeum dayakorum, a small songbird first formally described by scientists as recently as 2019.' WHERE artefact_id = 9;
UPDATE artefact SET artefact_description = 'Down feather from a Meleagris gallopavo, the wild turkey, native to North America and long domesticated by Indigenous peoples.' WHERE artefact_id = 10;
UPDATE artefact SET artefact_description = 'Skull and axial remains of Pseudocrypturus cercanaxius, an early bird relative of modern ostriches that lived millions of years ago.' WHERE artefact_id = 11;
UPDATE artefact SET artefact_description = 'Fossilized mandible with teeth from Megalagus, an early rabbit relative that lived in North America roughly 30 million years ago.' WHERE artefact_id = 12;
UPDATE artefact SET artefact_description = 'Fossilized partial mandible with teeth from Subhyracodon, an early rhinoceros ancestor that roamed North America over 30 million years ago.' WHERE artefact_id = 13;
UPDATE artefact SET artefact_description = 'Fossilized tooth from Thecachampsa, an extinct crocodilian that lived in what is now North America during the Miocene epoch.' WHERE artefact_id = 14;
UPDATE artefact SET artefact_description = 'Fossilized molar tooth from Scaldicetus, an extinct sperm whale ancestor that hunted prey in ancient oceans millions of years ago.' WHERE artefact_id = 15;
UPDATE artefact SET artefact_description = 'Skull specimen of Caiman yacare, a South American caiman species found across wetlands, rivers, and floodplains of the Pantanal region.' WHERE artefact_id = 16;
UPDATE artefact SET artefact_description = 'Specimen of Agama rueppelli occidentalis, an agamid lizard native to arid regions of northeastern Africa, known for its rapid movement.' WHERE artefact_id = 17;
UPDATE artefact SET artefact_description = 'Specimen of Testudo kleinmanni, one of the smallest tortoise species in the world and now critically endangered in the wild.' WHERE artefact_id = 18;
UPDATE artefact SET artefact_description = 'Specimen of Chamaeleo africanus, a chameleon species known for its colour-changing skin, used for camouflage and communicating mood.' WHERE artefact_id = 19;
UPDATE artefact SET artefact_description = 'Full skeletal mount of Stegosaurus, a plant-eating dinosaur recognised by the row of bony plates running along its spine.' WHERE artefact_id = 20;
UPDATE artefact SET artefact_description = 'Prepared slide of Anopheles quadrimaculatus, a mosquito species historically responsible for spreading malaria across parts of North America.' WHERE artefact_id = 21;
UPDATE artefact SET artefact_description = 'Mounted specimen of Panacea divalis, a brightly coloured butterfly native to the tropical forests of Central and South America.' WHERE artefact_id = 22;
UPDATE artefact SET artefact_description = 'Preserved section of an insect wing, showing the delicate vein structure that provides both strength and flexibility for flight.' WHERE artefact_id = 23;
UPDATE artefact SET artefact_description = 'Wooden box used for shipping a queen honeybee, allowing beekeepers to safely transport and introduce new queens to a hive.' WHERE artefact_id = 24;
UPDATE artefact SET artefact_description = 'Specimen of Dorcus titanus, a giant stag beetle whose males use oversized jaw-like mandibles to battle rivals for mates.' WHERE artefact_id = 25;
UPDATE artefact SET artefact_description = 'Shell specimen of the winged argonaut, Argonauta hians, a pelagic octopus that secretes a thin shell to protect its eggs.' WHERE artefact_id = 26;
UPDATE artefact SET artefact_description = 'Life-sized model of Balaenoptera musculus, the blue whale, the largest animal known to have ever lived on Earth.' WHERE artefact_id = 27;
UPDATE artefact SET artefact_description = 'Specimen of Panulirus interruptus, a spiny lobster found along the Pacific coast, identified by its long antennae and spiny shell.' WHERE artefact_id = 28;
UPDATE artefact SET artefact_description = 'Specimen of Asterias rubens, the common starfish, which can regenerate lost arms and moves using tiny tube feet.' WHERE artefact_id = 29;
UPDATE artefact SET artefact_description = 'Branching coral sample from the staghorn coral family, reef-building corals that grow in fast branching shapes resembling deer antlers.' WHERE artefact_id = 30;
UPDATE artefact SET artefact_description = 'Cobbler''s iron hammer used by an African American shoemaker, reflecting the everyday tools of skilled tradespeople in earlier centuries.' WHERE artefact_id = 31;
UPDATE artefact SET artefact_description = 'Metal scroll-saw blade that belonged to woodworker Peter Glass, used to cut intricate curved patterns into wood by hand.' WHERE artefact_id = 32;
UPDATE artefact SET artefact_description = 'Tongue plane made by Cesar Chelor, one of the earliest known Black American toolmakers, used to shape grooves in wood.' WHERE artefact_id = 33;
UPDATE artefact SET artefact_description = 'Patent model for a hand press, submitted to demonstrate a new mechanical design before the invention could be officially patented.' WHERE artefact_id = 34;
UPDATE artefact SET artefact_description = 'Tool with two cylindrical rods and handles, resembling scissors, likely used for gripping or shaping materials in a workshop setting.' WHERE artefact_id = 35;
UPDATE artefact SET artefact_description = 'Pen made from swan feathers. Quills were the primary Western writing tool for over a thousand years, before steel nibs.' WHERE artefact_id = 36;
UPDATE artefact SET artefact_description = 'Green, transparent glass bottle used to hold ink, a common household item before fountain pens and ink cartridges became widespread.' WHERE artefact_id = 37;
UPDATE artefact SET artefact_description = 'Patent model for a typewriter developed by George W. N. Yost, part of an invention that transformed 19th-century office work.' WHERE artefact_id = 38;
UPDATE artefact SET artefact_description = 'Notebook used by conductors, agents, drivers, and station managers to record details of overland mail routes and deliveries.' WHERE artefact_id = 39;
UPDATE artefact SET artefact_description = 'Model used to record Morse code signals, part of the telegraph technology that revolutionised long-distance communication in the 19th century.' WHERE artefact_id = 40;
UPDATE artefact SET artefact_description = 'Women''s wool cap embroidered with flowers and leaves, showing the decorative needlework traditionally used to personalise everyday garments.' WHERE artefact_id = 41;
UPDATE artefact SET artefact_description = 'Silver decorative shoe buckles, once a fashionable and practical way to fasten footwear before laces and modern shoe fastenings.' WHERE artefact_id = 42;
UPDATE artefact SET artefact_description = 'Man''s dressing gown with contrasting front edgings, lapels, and cuffs, worn as informal indoor clothing in earlier centuries.' WHERE artefact_id = 43;
UPDATE artefact SET artefact_description = 'Quarter section of a printed scarf featuring a wavy floral border, used to test textile patterns before full production.' WHERE artefact_id = 44;
UPDATE artefact SET artefact_description = 'Leather gloves with gauntlet cuffs, cut in a delicate lace-like pattern to combine protection with decorative craftsmanship.' WHERE artefact_id = 45;
UPDATE artefact SET artefact_description = 'A spoon carved from wood, one of the oldest and most common food preparation tools used across many cultures.' WHERE artefact_id = 46;
UPDATE artefact SET artefact_description = 'Sterling silver tea pot belonging to George III, reflecting the elaborate tableware associated with formal tea traditions of the era.' WHERE artefact_id = 47;
UPDATE artefact SET artefact_description = 'Silver-plated ladle used for serving soup, a practical piece of formal dinnerware common in wealthier households of the period.' WHERE artefact_id = 48;
UPDATE artefact SET artefact_description = 'Footed bowl engraved with a ship sailing calm waters, likely used as a decorative or ceremonial serving piece.' WHERE artefact_id = 49;
UPDATE artefact SET artefact_description = 'Tapered, curved knife with a pointed blade, likely used for food preparation or serving at the dining table.' WHERE artefact_id = 50;
UPDATE artefact SET artefact_description = 'Oak table finished in red, an example of the sturdy, functional furniture found in many 19th-century homes.' WHERE artefact_id = 51;
UPDATE artefact SET artefact_description = 'Walnut chair with a fabric seat, combining durable hardwood construction with upholstered comfort common in period furniture.' WHERE artefact_id = 52;
UPDATE artefact SET artefact_description = 'Round clock made for a desk, designed to keep time in a compact form suitable for a study or office.' WHERE artefact_id = 53;
UPDATE artefact SET artefact_description = 'Wooden bed frame designed by Henry Boyd, a formerly enslaved inventor known for his improved bedstead joint design.' WHERE artefact_id = 54;
UPDATE artefact SET artefact_description = 'A fabric curtain used to block out sunlight, a simple household textile that also added privacy and decoration indoors.' WHERE artefact_id = 55;
UPDATE artefact SET artefact_description = 'Figurine of a man riding a bicycle, a popular toy that reflected the growing craze for cycling in this era.' WHERE artefact_id = 56;
UPDATE artefact SET artefact_description = 'Paper doll of a female figure in a yellow dress, a popular and inexpensive toy for children in earlier decades.' WHERE artefact_id = 57;
UPDATE artefact SET artefact_description = 'Toy fire pumper made from tin, iron, and wood, modelled after real horse-drawn pumper engines used by firefighters.' WHERE artefact_id = 58;
UPDATE artefact SET artefact_description = 'Silver-plated toy speaking trumpet, a novelty instrument designed to amplify a child''s voice rather than play music.' WHERE artefact_id = 59;
UPDATE artefact SET artefact_description = 'A stuffed teddy bear, a toy style that emerged in the early 1900s, named after President Theodore ''Teddy'' Roosevelt.' WHERE artefact_id = 60;
