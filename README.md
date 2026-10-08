# Behaviour-Driven Adaptive Digital Museum – MSc Research Project

## Tools & Skills
- **Development:** PHP, MySQL (PDO), JavaScript, HTML, CSS, MAMP, Visual Studio Code
- **Analysis:** Python – pandas, seaborn, Matplotlib; paired t-tests, Wilcoxon signed-rank tests, Shapiro–Wilk tests, effect sizes; thematic analysis
- **Data:** 13-table relational database, ERDs, data dictionary, behavioural event logging, metadata curation and cleaning
- **Research:** Design Science Research, within-subject counterbalanced experiment, ethics approval, GDPR, risk registers, phased testing

## What I Did
This was my research project for my Data Analytics MSc. It asked whether real-time behaviour, such as clicks, time spent viewing an artefact, revisits and navigation, can be modelled to estimate a visitor's interest, and whether adapting what a digital museum shows in response leads to richer exploration.

- **System build:** I built two functionally identical web prototypes, one static and one adaptive, over 10 test-gated build phases. In the adaptive version, 8 behavioural signals feed 27 rules across 7 categories. These produce an interest score that updates a four-artefact suggestion panel, with serendipity rules (and a rate limit) designed to surface unexpected artefacts.
- **Data curation:** I curated 60 artefacts across 2 themes and 12 subthemes from the Smithsonian Open Access collection and cleaned their metadata against defined rules for titles, descriptions and images.
- **Database:** I designed a 13-table MySQL database that links every logged interaction to its participant, session, study condition and adaptive rule.
- **Study & analysis:** In an ethically approved study, 30 participants used both versions in a counterbalanced order, generating 8,928 logged events and 362 questionnaire responses. I analysed the data in Python using paired statistical tests and carried out a thematic analysis of 61 free-text comments.

## Key Findings
- The adaptive engine was reliable, triggering 3,130 adaptive events under its final settings without breaching the serendipity rate limit.
- Perceived relevance rose from 3.07 to 4.00 out of 5 (p = .002), with no participant rating the static version higher. Overall experience also improved, from 4.07 to 4.47 (p = .003).
- Two-thirds of participants (20 of 30) preferred the adaptive version.
- Total viewing time fell from 212 to 181 seconds (p = .001) while revisits more than doubled (p = .006). This suggests participants refocused their attention on artefacts they had already noticed rather than engaging less.
- Exploratory freedom and surprise did not change significantly, so the adaptive version preserved discovery rather than measurably enhancing it.
- Overall, lightweight rule-based adaptation can improve relevance and user preference without needing machine learning infrastructure.

## Files
| File | Description |
|------|-------------|
| `DC25259628final.docx` | Final project report |
| `DC25259628appendices.docx` | Appendices: design diagrams, data dictionary, rule specifications, test results, analysis outputs and ethics documents |
| `digitalmuseum_database.sql` | MySQL script to create the database with the artefact metadata, themes, adaptive rules and questionnaire items (participant data not included) |
| `Artefact Metadata.xlsx` | Curated metadata for the 60 artefacts |
| `Digital Museum Research Poster.png` | Research poster summarising the project's aims, method and key findings |
| `digitalmuseum/` | Source code for the prototypes: participant-facing PHP pages, api/ endpoints, includes/ adaptive engine modules, js/ logging and adaptive scripts, css/ styling, config/ database connection, database/ setup scripts, tests/ for each build phase and exports/ data export script |
